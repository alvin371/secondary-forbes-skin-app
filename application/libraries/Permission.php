<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Permission Library
 * 
 * Handles dynamic user permissions based on positions and individual overrides
 * Integrates with the quest level system and provides easy permission checking
 */
class Permission
{
    protected $CI;
    protected $user_permissions_cache = [];
    protected $permission_tables_exist = null;
    protected $user_permission_rows_cache = [];
    protected $user_permission_maps_cache = [];
    protected $fallback_permission_maps = [];
    protected $allowed_actions = ['view', 'create', 'edit', 'delete', 'approve'];
    
    public function __construct()
    {
        $this->CI =& get_instance();
        $this->CI->load->model('mymodel');
    }
    
    /**
     * Check if user has specific permission for a module
     * 
     * @param int $user_id User ID
     * @param string $module_name Module name
     * @param string $action Permission action (view, create, edit, delete)
     * @return bool
     */
    public function check_permission($user_id, $module_name, $action = 'view')
    {
        $user_id = (int) $user_id;
        $module_name = trim((string) $module_name);
        $action = $this->sanitize_action($action);
        $cache_key = "{$user_id}_{$module_name}_{$action}";

        if (isset($this->user_permissions_cache[$cache_key])) {
            return $this->user_permissions_cache[$cache_key];
        }

        $has_permission = false;
        $permissions_map = $this->get_user_permission_map($user_id);
        $module_key = strtolower($module_name);

        if (isset($permissions_map[$module_key][$action])) {
            $has_permission = $permissions_map[$module_key][$action];
        } else {
            $has_permission = $this->fallback_permission_check($user_id, $module_name, $action);
        }

        $this->user_permissions_cache[$cache_key] = $has_permission;

        return $has_permission;
    }

    /**
     * Get permissions for multiple modules at once.
     *
     * @param int $user_id
     * @param array $modules
     * @param string $action
     * @return array
     */
    public function get_bulk_permissions($user_id, array $modules, $action = 'view')
    {
        $result = [];
        $action = $this->sanitize_action($action);

        foreach (array_values(array_unique($modules)) as $module_name) {
            $result[$module_name] = $this->check_permission($user_id, $module_name, $action);
        }

        return $result;
    }
    
    /**
     * Check if user has access to a controller (any permission)
     * 
     * @param int $user_id User ID
     * @param string $controller Controller name
     * @return bool
     */
    public function has_module_access($user_id, $controller)
    {
        $user_id = (int) $user_id;
        $controller_key = strtolower(trim((string) $controller));
        $permissions_by_controller = $this->get_user_permissions_by_controller($user_id);

        if (isset($permissions_by_controller[$controller_key])) {
            return $permissions_by_controller[$controller_key];
        }

        return $this->fallback_permission_check($user_id, $controller, 'view');
    }
    
    /**
     * Get all permissions for a user
     * 
     * @param int $user_id User ID
     * @return array
     */
    public function get_user_permissions($user_id)
    {
        $rows = $this->get_user_permission_rows((int) $user_id);

        if (!empty($rows)) {
            return array_values(array_filter($rows, function ($row) {
                return !empty($row['can_view']) ||
                    !empty($row['can_create']) ||
                    !empty($row['can_edit']) ||
                    !empty($row['can_delete']) ||
                    !empty($row['can_approve']);
            }));
        }

        try {
            return [];
        } catch (Exception $e) {
            return [];
        }
    }
    
    /**
     * Get user's accessible modules for sidebar
     * 
     * @param int $user_id User ID
     * @return array Hierarchical module structure
     */
    public function get_user_sidebar_modules($user_id)
    {
        $user_id = (int) $user_id;
        $permissions = $this->CI->db->query("
            SELECT
                m.id,
                m.name,
                m.display_name,
                m.controller,
                m.icon,
                m.parent_id,
                m.sort_order,
                ump.can_view,
                ump.can_create,
                ump.can_edit,
                ump.can_delete
            FROM modules m
            LEFT JOIN user_module_permissions ump ON m.id = ump.module_id AND ump.user_id = $user_id
            WHERE m.is_active = 1
            AND (ump.can_view = 1 OR ump.can_create = 1 OR ump.can_edit = 1 OR ump.can_delete = 1)
            ORDER BY m.sort_order, m.display_name
        ")->result_array();
        
        return $this->build_module_tree($permissions);
    }
    
    /**
     * Build hierarchical module tree
     * 
     * @param array $modules Flat module array
     * @param int $parent_id Parent ID
     * @return array
     */
    private function build_module_tree($modules, $parent_id = null)
    {
        $tree = [];
        
        foreach ($modules as $module) {
            if ($module['parent_id'] == $parent_id) {
                $module['children'] = $this->build_module_tree($modules, $module['id']);
                $tree[] = $module;
            }
        }
        
        return $tree;
    }
    
    /**
     * Check if user can perform action on current controller
     * Uses current CI controller and method
     * 
     * @param int $user_id User ID
     * @param string $action Permission action
     * @return bool
     */
    public function can_access_current($user_id, $action = 'view')
    {
        $controller = $this->CI->router->fetch_class();
        
        // Map controller to module name
        $module_mapping = $this->get_controller_module_mapping();
        $module_name = isset($module_mapping[$controller]) ? $module_mapping[$controller] : $controller;
        
        return $this->check_permission($user_id, $module_name, $action);
    }
    
    /**
     * Check if permission tables exist
     * 
     * @return bool
     */
    private function permission_tables_exist()
    {
        if ($this->permission_tables_exist !== null) {
            return $this->permission_tables_exist;
        }

        $this->permission_tables_exist =
            $this->CI->db->table_exists('modules') &&
            $this->CI->db->table_exists('roles') &&
            $this->CI->db->table_exists('role_permissions') &&
            $this->CI->db->table_exists('user_roles');

        return $this->permission_tables_exist;
    }

    /**
     * Fallback permission check when view doesn't exist
     * Queries role_permissions directly via user_roles
     *
     * @param int $user_id User ID
     * @param string $module_name Module name
     * @param string $action Permission action
     * @return bool
     */
    private function fallback_permission_check($user_id, $module_name, $action)
    {
        $action = $this->sanitize_action($action);
        $module_key = strtolower(trim((string) $module_name));
        $fallback = $this->get_fallback_permission_map((int) $user_id);

        if (isset($fallback['modules'][$module_key][$action])) {
            return $fallback['modules'][$module_key][$action];
        }

        if ($fallback['is_admin']) {
            return true;
        }

        if ($action === 'view' && in_array($module_key, ['dashboard', 'profile', 'home'], true)) {
            return true;
        }

        if ($fallback['legacy_admin']) {
            return true;
        }

        if ($action === 'view' && in_array($module_key, ['dashboard', 'profile', 'quest'], true)) {
            return true;
        }

        return false;
    }

    private function sanitize_action($action)
    {
        $action = strtolower(trim((string) $action));

        return in_array($action, $this->allowed_actions, true) ? $action : 'view';
    }

    private function get_user_permission_rows($user_id)
    {
        if (array_key_exists($user_id, $this->user_permission_rows_cache)) {
            return $this->user_permission_rows_cache[$user_id];
        }

        if (!$this->permission_tables_exist() || !$this->CI->db->table_exists('user_module_permissions')) {
            $this->user_permission_rows_cache[$user_id] = [];
            return [];
        }

        try {
            $rows = $this->CI->db->query("
                SELECT
                    user_id,
                    module_id,
                    module_name,
                    module_display_name,
                    controller,
                    parent_id,
                    can_view,
                    can_create,
                    can_edit,
                    can_delete,
                    can_approve,
                    has_override
                FROM user_module_permissions
                WHERE user_id = ?
                ORDER BY module_name
            ", [$user_id])->result_array();
        } catch (Exception $e) {
            $rows = [];
        }

        foreach ($rows as &$row) {
            foreach (['can_view', 'can_create', 'can_edit', 'can_delete', 'can_approve', 'has_override'] as $column) {
                $row[$column] = (int) ($row[$column] ?? 0);
            }
        }
        unset($row);

        $this->user_permission_rows_cache[$user_id] = $rows;

        return $rows;
    }

    private function get_user_permission_map($user_id)
    {
        if (isset($this->user_permission_maps_cache[$user_id]['modules'])) {
            return $this->user_permission_maps_cache[$user_id]['modules'];
        }

        $rows = $this->get_user_permission_rows($user_id);
        $module_map = [];
        $controller_map = [];

        foreach ($rows as $row) {
            $module_key = strtolower((string) ($row['module_name'] ?? ''));
            $controller_key = strtolower((string) ($row['controller'] ?? ''));

            if ($module_key !== '') {
                $module_map[$module_key] = [
                    'view' => !empty($row['can_view']),
                    'create' => !empty($row['can_create']),
                    'edit' => !empty($row['can_edit']),
                    'delete' => !empty($row['can_delete']),
                    'approve' => !empty($row['can_approve']),
                ];
            }

            if ($controller_key !== '') {
                $controller_map[$controller_key] = $controller_map[$controller_key] ?? false;
                $controller_map[$controller_key] = $controller_map[$controller_key]
                    || !empty($row['can_view'])
                    || !empty($row['can_create'])
                    || !empty($row['can_edit'])
                    || !empty($row['can_delete'])
                    || !empty($row['can_approve']);
            }
        }

        $this->user_permission_maps_cache[$user_id] = [
            'modules' => $module_map,
            'controllers' => $controller_map,
        ];

        return $module_map;
    }

    private function get_user_permissions_by_controller($user_id)
    {
        if (!isset($this->user_permission_maps_cache[$user_id]['controllers'])) {
            $this->get_user_permission_map($user_id);
        }

        return $this->user_permission_maps_cache[$user_id]['controllers'] ?? [];
    }

    private function get_fallback_permission_map($user_id)
    {
        if (isset($this->fallback_permission_maps[$user_id])) {
            return $this->fallback_permission_maps[$user_id];
        }

        $fallback = [
            'modules' => [],
            'is_admin' => false,
            'legacy_admin' => false,
        ];

        try {
            $rows = $this->CI->db->query("
                SELECT
                    m.name AS module_name,
                    MAX(rp.can_view) AS can_view,
                    MAX(rp.can_create) AS can_create,
                    MAX(rp.can_edit) AS can_edit,
                    MAX(rp.can_delete) AS can_delete,
                    MAX(COALESCE(rp.can_approve, 0)) AS can_approve
                FROM user_roles ur
                INNER JOIN roles r ON ur.role_id = r.id AND r.is_active = 1
                INNER JOIN role_permissions rp ON r.id = rp.role_id
                INNER JOIN modules m ON rp.module_id = m.id AND m.is_active = 1
                WHERE ur.user_id = ?
                GROUP BY m.name
            ", [$user_id])->result_array();

            foreach ($rows as $row) {
                $module_key = strtolower((string) $row['module_name']);
                $fallback['modules'][$module_key] = [
                    'view' => !empty($row['can_view']),
                    'create' => !empty($row['can_create']),
                    'edit' => !empty($row['can_edit']),
                    'delete' => !empty($row['can_delete']),
                    'approve' => !empty($row['can_approve']),
                ];
            }

            $role_rows = $this->CI->db->query("
                SELECT r.name
                FROM user_roles ur
                INNER JOIN roles r ON ur.role_id = r.id
                WHERE ur.user_id = ? AND r.is_active = 1
            ", [$user_id])->result_array();

            foreach ($role_rows as $role) {
                if (in_array(strtolower((string) $role['name']), ['super_admin', 'admin'], true)) {
                    $fallback['is_admin'] = true;
                    break;
                }
            }
        } catch (Exception $e) {
            $fallback['modules'] = [];
        }

        try {
            $user = $this->CI->db->query("
                SELECT role
                FROM user
                WHERE id = ?
                LIMIT 1
            ", [$user_id])->row_array();

            if (!empty($user) && in_array((string) $user['role'], ['1', '2', '7'], true)) {
                $fallback['legacy_admin'] = true;
            }
        } catch (Exception $e) {
            $fallback['legacy_admin'] = false;
        }

        $this->fallback_permission_maps[$user_id] = $fallback;

        return $fallback;
    }

    /**
     * Get controller to module name mapping
     * 
     * @return array
     */
    private function get_controller_module_mapping()
    {
        return [
            'dashboard' => 'dashboard',
            'report' => 'report',
            'expense' => 'expense',
            'overview' => 'overview',
            'ads' => 'advertiser', // Special handling for ads with parameters
            'influencer' => 'influencer',
            'influencer_dummy' => 'influencer_dummy',
            'endorse_campaign' => 'endorse_campaign',
            'calendar' => 'calendar',
            'payment' => 'payment',
            'codeboost' => 'codeboost',
            'marketplace_account' => 'marketplace_account',
            'transaction' => 'transaction',
            'transaction_item' => 'transaction_item',
            'crm' => 'crm_mg', // Default, may need brand parameter handling
            'group_wa' => 'group_wa',
            'stock' => 'stock',
            'product' => 'product',
            'product_3rd' => 'product_3rd',
            'discount' => 'discount',
            'marketplace' => 'marketplace',
            'shipping' => 'shipping',
            'quest_level' => 'quest_level',
            'position' => 'position',
            'benefit' => 'benefit',
            'quest' => 'quest',
            'milestone' => 'milestone',
            'user' => 'user',
            'profile' => 'profile',
            'customer' => 'customer',
            'label' => 'label',
            'testimoni' => 'testimoni',
            'scraper' => 'scraper',
            'meta_account' => 'meta_account'
        ];
    }
    
    /**
     * Check specific ads module permission based on marketplace parameter
     * 
     * @param int $user_id User ID
     * @param string $marketplace Marketplace (tiktok, meta, shopee, lazada)
     * @param string $action Permission action
     * @return bool
     */
    public function check_ads_permission($user_id, $marketplace, $action = 'view')
    {
        $module_name = 'ads_' . strtolower($marketplace);
        return $this->check_permission($user_id, $module_name, $action);
    }
    
    /**
     * Check CRM permission based on brand parameter
     * 
     * @param int $user_id User ID
     * @param string $brand Brand (MG, POME)
     * @param string $action Permission action
     * @return bool
     */
    public function check_crm_permission($user_id, $brand, $action = 'view')
    {
        $module_name = 'crm_' . strtolower($brand);
        return $this->check_permission($user_id, $module_name, $action);
    }
    
    /**
     * Enforce permission check - redirect if no access
     * 
     * @param int $user_id User ID
     * @param string $module_name Module name
     * @param string $action Permission action
     * @param string $redirect_url Redirect URL on failure
     */
    public function enforce_permission($user_id, $module_name, $action = 'view', $redirect_url = null)
    {
        if (!$this->check_permission($user_id, $module_name, $action)) {
            if (!$redirect_url) {
                $redirect_url = base_url() . 'dashboard';
            }
            redirect($redirect_url);
        }
    }
    
    /**
     * Show 403 error page for permission denied
     * Alternative to enforce_permission that shows error page instead of redirect
     * 
     * @param int $user_id User ID
     * @param string $module_name Module name
     * @param string $action Permission action
     * @param array $data Additional data to pass to error page
     */
    public function show_403_if_no_permission($user_id, $module_name, $action = 'view', $data = [])
    {
        if (!$this->check_permission($user_id, $module_name, $action)) {
            // Set HTTP status code
            $this->CI->output->set_status_header(403);
            
            // Prepare data for the error page
            $error_data = array_merge([
                'heading' => 'Access Forbidden',
                'message' => 'You do not have permission to access this resource.',
                'module' => $module_name,
                'action' => $action,
                'user_id' => $user_id
            ], $data);
            
            // Load and display the 403 error page
            $this->CI->load->view('errors/html/error_403', $error_data);
            exit;
        }
    }
    
    /**
     * Enhanced enforce permission with option to show 403 page
     * 
     * @param int $user_id User ID
     * @param string $module_name Module name
     * @param string $action Permission action
     * @param bool $show_403 Whether to show 403 page instead of redirect
     * @param string $redirect_url Redirect URL on failure (if not showing 403)
     * @param array $error_data Additional data for 403 page
     */
    public function enforce_permission_with_403($user_id, $module_name, $action = 'view', $show_403 = false, $redirect_url = null, $error_data = [])
    {
        if (!$this->check_permission($user_id, $module_name, $action)) {
            if ($show_403) {
                $this->show_403_if_no_permission($user_id, $module_name, $action, $error_data);
            } else {
                if (!$redirect_url) {
                    $redirect_url = base_url() . 'dashboard';
                }
                redirect($redirect_url);
            }
        }
    }
    
    /**
     * Clear permission cache for user
     * 
     * @param int $user_id User ID
     */
    public function clear_user_cache($user_id = null)
    {
        if ($user_id) {
            foreach (array_keys($this->user_permissions_cache) as $key) {
                if (strpos($key, $user_id . '_') === 0) {
                    unset($this->user_permissions_cache[$key]);
                }
            }

            unset($this->user_permission_rows_cache[$user_id]);
            unset($this->user_permission_maps_cache[$user_id]);
            unset($this->fallback_permission_maps[$user_id]);
        } else {
            $this->user_permissions_cache = [];
            $this->user_permission_rows_cache = [];
            $this->user_permission_maps_cache = [];
            $this->fallback_permission_maps = [];
        }
    }
    
    /**
     * Get all positions with their permission counts
     * For management interface
     * 
     * @return array
     */
    public function get_positions_with_permissions()
    {
        return $this->CI->mymodel->selectWithQuery("
            SELECT 
                p.id,
                p.name as position_name,
                ql.name as quest_level_name,
                ql.level_order,
                COUNT(perm.id) as total_permissions,
                SUM(perm.can_view) as view_permissions,
                SUM(perm.can_create) as create_permissions,
                SUM(perm.can_edit) as edit_permissions,
                SUM(perm.can_delete) as delete_permissions
            FROM positions p
            JOIN quest_levels ql ON p.level_id = ql.id
            LEFT JOIN permissions perm ON p.id = perm.position_id
            GROUP BY p.id, p.name, ql.name, ql.level_order
            ORDER BY ql.level_order, p.name
        ");
    }
    
    /**
     * Get permission matrix for a specific position
     * 
     * @param int $position_id Position ID
     * @return array
     */
    public function get_position_permissions($position_id)
    {
        return $this->CI->mymodel->selectWithQuery("
            SELECT 
                m.id as module_id,
                m.name as module_name,
                m.display_name,
                m.parent_id,
                COALESCE(p.can_view, 0) as can_view,
                COALESCE(p.can_create, 0) as can_create,
                COALESCE(p.can_edit, 0) as can_edit,
                COALESCE(p.can_delete, 0) as can_delete
            FROM modules m
            LEFT JOIN permissions p ON m.id = p.module_id AND p.position_id = ?
            WHERE m.is_active = 1
            ORDER BY m.sort_order, m.display_name
        ", [$position_id]);
    }
    
    /**
     * Update position permissions
     * 
     * @param int $position_id Position ID
     * @param array $permissions Permission data
     * @return bool
     */
    public function update_position_permissions($position_id, $permissions)
    {
        // Start transaction
        $this->CI->db->trans_start();
        
        try {
            // Delete existing permissions for this position
            $this->CI->mymodel->deleteData('permissions', ['position_id' => $position_id]);
            
            // Insert new permissions
            foreach ($permissions as $module_id => $perms) {
                if (isset($perms['can_view']) || isset($perms['can_create']) || 
                    isset($perms['can_edit']) || isset($perms['can_delete'])) {
                    
                    $data = [
                        'position_id' => $position_id,
                        'module_id' => $module_id,
                        'can_view' => isset($perms['can_view']) ? 1 : 0,
                        'can_create' => isset($perms['can_create']) ? 1 : 0,
                        'can_edit' => isset($perms['can_edit']) ? 1 : 0,
                        'can_delete' => isset($perms['can_delete']) ? 1 : 0
                    ];
                    
                    $this->CI->mymodel->insertData('permissions', $data);
                }
            }
            
            $this->CI->db->trans_complete();
            
            if ($this->CI->db->trans_status() === FALSE) {
                return false;
            }
            
            // Clear cache
            $this->clear_user_cache();
            
            return true;
            
        } catch (Exception $e) {
            $this->CI->db->trans_rollback();
            return false;
        }
    }
}
