<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Endorse_sync
{
    protected $CI;

    public function __construct()
    {
        $this->CI =& get_instance();
        $this->CI->load->database();
        $this->CI->load->model('mymodel');
        $this->CI->load->library('template');
    }

    public function classifyError($response, $defaultMessage = 'Gagal mengambil data sosial media.')
    {
        $message = trim((string) ($response['msg'] ?? $defaultMessage));
        $normalized = strtolower($message);

        if ($normalized === '') {
            $message = $defaultMessage;
            $normalized = strtolower($message);
        }

        $errorClass = 'transient';
        if (
            strpos($normalized, 'video id tidak ditemukan') !== false ||
            strpos($normalized, 'url tidak ditemukan') !== false ||
            strpos($normalized, 'response tiktok') !== false ||
            strpos($normalized, 'tidak ditemukan') !== false ||
            strpos($normalized, 'invalid tiktok url') !== false
        ) {
            $errorClass = 'permanent';
        }

        return [
            'error_class' => $errorClass,
            'error_message' => $message,
        ];
    }

    public function isKnownPermanentFailure($message)
    {
        $normalized = strtolower(trim((string) $message));
        if ($normalized === '') {
            return false;
        }

        return strpos($normalized, 'video id tidak ditemukan') !== false
            || strpos($normalized, 'url tidak ditemukan') !== false
            || strpos($normalized, 'response tiktok') !== false
            || strpos($normalized, 'invalid tiktok url') !== false;
    }

    public function apply(array $endorseRow, array $response, $today = null, $userId = 0)
    {
        $today = $today ?: date('Y-m-d');
        $idEndorse = intval($endorseRow['id'] ?? 0);
        if ($idEndorse <= 0) {
            return [
                'status' => false,
                'error_class' => 'permanent',
                'error_message' => 'Data endorse tidak valid.',
            ];
        }

        if (($response['status'] ?? false) !== true || !is_array($response['data'] ?? null)) {
            $error = $this->classifyError($response);
            return [
                'status' => false,
                'error_class' => $error['error_class'],
                'error_message' => $error['error_message'],
            ];
        }

        $responseData = $response['data'];
        $hasStats = intval($responseData['view'] ?? 0) > 0
            || intval($responseData['like'] ?? 0) > 0
            || intval($responseData['comment'] ?? 0) > 0
            || intval($responseData['share'] ?? 0) > 0
            || intval($responseData['collect'] ?? 0) > 0;

        if (!$hasStats) {
            return [
                'status' => false,
                'error_class' => 'empty',
                'error_message' => trim((string) ($response['msg'] ?? 'Data sosial media tidak ditemukan.')),
            ];
        }

        $userId = intval($userId);
        $totalCost = doubleval($endorseRow['total_cost'] ?? 0);
        $prev = $this->CI->mymodel->selectWithQuery("SELECT *
            FROM endorse_logs
            WHERE id_endorse = '{$idEndorse}' AND date < '{$today}' AND views_after > 0
            ORDER BY date DESC
            LIMIT 1");
        $prev = $prev[0] ?? [];

        $todayLog = $this->CI->mymodel->selectWithQuery("SELECT id
            FROM endorse_logs
            WHERE id_endorse = '{$idEndorse}' AND date = '{$today}'
            LIMIT 1");
        $todayLog = $todayLog[0] ?? [];

        $prevLikes = intval($prev['likes_after'] ?? 0);
        $prevComment = intval($prev['comment_after'] ?? 0);
        $prevShareSave = intval($prev['share_save_after'] ?? 0);
        $prevViews = intval($prev['views_after'] ?? 0);

        $likesAfter = intval($responseData['like'] ?? 0);
        $commentAfter = intval($responseData['comment'] ?? 0);
        $shareAfter = doubleval($responseData['share'] ?? 0) + doubleval($responseData['collect'] ?? 0);
        $viewsAfter = intval($responseData['view'] ?? 0);

        $isFyp = isset($endorseRow['is_fyp']) ? strval($endorseRow['is_fyp']) : '0';
        if ($viewsAfter >= 50000) {
            $idInfluencer = intval($endorseRow['influencer'] ?? 0);
            if ($idInfluencer > 0) {
                $creator = $this->CI->mymodel->selectWithQuery("SELECT follower FROM influencer WHERE id = '{$idInfluencer}' LIMIT 1");
                $follower = intval($creator[0]['follower'] ?? 0);
                if ($follower > 0) {
                    $threshold = intval($follower * 30 / 100);
                    if ($viewsAfter >= $threshold) {
                        $isFyp = '1';
                    }
                } else {
                    $isFyp = '1';
                }
            } else {
                $isFyp = '1';
            }
        }

        $now = date('Y-m-d H:i:s');
        $cpmAfter = ($totalCost > 0 && $viewsAfter > 0) ? ($totalCost / $viewsAfter * 1000) : 0;

        $endorseUpdate = [
            'status' => strval($endorseRow['status'] ?? ''),
            'status_campaign' => strval($endorseRow['status_campaign'] ?? ''),
            'likes' => doubleval($likesAfter),
            'comment' => doubleval($commentAfter),
            'share_save' => doubleval($shareAfter),
            'views' => doubleval($viewsAfter),
            'cpm' => doubleval($cpmAfter),
            'is_fyp' => strval($isFyp),
            'sync_at' => $now,
            'posting_at' => strval($responseData['created_at'] ?? ($endorseRow['posting_at'] ?? '')),
            'updated_at' => $now,
        ];
        if ($userId > 0) {
            $endorseUpdate['updated_by'] = strval($userId);
        }

        $this->applyTikTokMetadata($endorseRow, $responseData, $endorseUpdate, $now);

        $likesDelta = $likesAfter - $prevLikes;
        $commentDelta = $commentAfter - $prevComment;
        $shareDelta = $shareAfter - $prevShareSave;
        $viewsDelta = $viewsAfter - $prevViews;
        $cpmDelta = ($totalCost > 0 && $viewsDelta > 0) ? ($totalCost / $viewsDelta * 1000) : 0;
        $cpmBefore = ($totalCost > 0 && $prevViews > 0) ? ($totalCost / $prevViews * 1000) : 0;

        $logPayload = [
            'status' => strval($endorseRow['status'] ?? ''),
            'status_campaign' => strval($endorseRow['status_campaign'] ?? ''),
            'id_endorse' => strval($idEndorse),
            'id_campaign' => strval($endorseRow['id_campaign'] ?? 0),
            'influencer' => strval($endorseRow['influencer'] ?? ''),
            'date' => $today,
            'total_cost' => doubleval($totalCost),
            'link_upload' => strval($endorseRow['link_upload'] ?? ''),
            'platform' => strval($endorseRow['platform'] ?? ''),
            'likes' => doubleval($likesDelta),
            'comment' => doubleval($commentDelta),
            'share_save' => doubleval($shareDelta),
            'views' => doubleval($viewsDelta),
            'cpm' => doubleval($cpmDelta),
            'likes_before' => intval($prevLikes),
            'comment_before' => intval($prevComment),
            'share_save_before' => intval($prevShareSave),
            'views_before' => intval($prevViews),
            'cpm_before' => doubleval($cpmBefore),
            'likes_after' => intval($likesAfter),
            'comment_after' => intval($commentAfter),
            'share_save_after' => intval($shareAfter),
            'views_after' => intval($viewsAfter),
            'cpm_after' => doubleval($cpmAfter),
        ];

        if (!empty($endorseRow['brand'])) {
            $logPayload['brand'] = strval($endorseRow['brand']);
        }

        $this->CI->db->trans_start();
        $this->CI->db->update('endorse', $endorseUpdate, ['id' => $idEndorse]);
        if (!empty($todayLog['id'])) {
            $logPayload['updated_at'] = $now;
            if ($userId > 0) {
                $logPayload['updated_by'] = strval($userId);
            }
            $this->CI->db->update('endorse_logs', $logPayload, ['id' => intval($todayLog['id'])]);
        } else {
            $logPayload['created_at'] = $now;
            if ($userId > 0) {
                $logPayload['created_by'] = strval($userId);
            }
            $this->CI->db->insert('endorse_logs', $logPayload);
        }
        $this->CI->db->trans_complete();

        if ($this->CI->db->trans_status() === false) {
            return [
                'status' => false,
                'error_class' => 'transient',
                'error_message' => 'Gagal menyimpan hasil refresh endorse.',
            ];
        }

        return [
            'status' => true,
            'endorse_update' => $endorseUpdate,
            'response_data' => $responseData,
        ];
    }

    protected function applyTikTokMetadata(array $endorseRow, array $responseData, array &$endorseUpdate, $fetchedAt)
    {
        if (strtolower((string) ($endorseRow['platform'] ?? '')) !== 'tiktok') {
            return;
        }

        $currentContentId = trim((string) ($this->CI->template->extract_tiktok_content_id($endorseRow['link_upload'] ?? '') ?? ''));
        $storedContentId = trim((string) ($endorseRow['tiktok_content_id'] ?? ''));
        $storedMediaType = trim((string) ($endorseRow['tiktok_media_type'] ?? ''));
        $storedCover = trim((string) ($endorseRow['tiktok_cover'] ?? ''));
        $storedContentLink = trim((string) ($endorseRow['tiktok_content_link'] ?? ''));
        $normalizedContentId = trim((string) ($responseData['content_id'] ?? $currentContentId));
        $mediaType = trim((string) ($responseData['media_type'] ?? ''));
        $contentLink = trim((string) ($responseData['video_link'] ?? ''));
        $cover = trim((string) ($responseData['cover'] ?? ''));

        $shouldRefresh = $storedContentId === ''
            || $storedMediaType === ''
            || $storedCover === ''
            || $storedContentLink === ''
            || ($currentContentId !== '' && $storedContentId !== '' && $currentContentId !== $storedContentId);

        if (!$shouldRefresh && $normalizedContentId === '' && $mediaType === '' && $contentLink === '' && $cover === '') {
            return;
        }

        if ($normalizedContentId !== '') {
            $endorseUpdate['tiktok_content_id'] = $normalizedContentId;
        }
        if ($mediaType !== '') {
            $endorseUpdate['tiktok_media_type'] = $mediaType;
        }
        if ($cover !== '') {
            $endorseUpdate['tiktok_cover'] = $cover;
        }
        if ($contentLink !== '') {
            $endorseUpdate['tiktok_content_link'] = $contentLink;
        }
        if (
            isset($endorseUpdate['tiktok_content_id']) ||
            isset($endorseUpdate['tiktok_media_type']) ||
            isset($endorseUpdate['tiktok_cover']) ||
            isset($endorseUpdate['tiktok_content_link'])
        ) {
            $endorseUpdate['tiktok_fetched_at'] = $fetchedAt;
        }
    }

    public function update_campaign_parent($idParent, $userId = 0)
    {
        $idParent = intval($idParent);
        if ($idParent <= 0) {
            return false;
        }

        $userId = intval($userId ?: ($_SESSION['user']['id'] ?? 0));
        $today = date('Y-m-d');
        $query = $this->CI->mymodel->selectWithQuery("SELECT id
            FROM endorse_campaign_logs
            WHERE id_campaign = '{$idParent}' AND date = '{$today}'");
        $query = $query[0] ?? [];

        $queryYesterday = $this->CI->mymodel->selectWithQuery("SELECT *
            FROM endorse_campaign_logs
            WHERE id_campaign = '{$idParent}' AND date < '{$today}'
            ORDER BY date DESC
            LIMIT 1");
        $queryYesterday = $queryYesterday[0] ?? [];

        $itemDetail = $this->CI->mymodel->selectWithQuery("SELECT
            SUM(total_cost) as total_cost,
            COUNT(id) as count_endorse,
            SUM(likes) as likes,
            SUM(comment) as comment,
            SUM(share_save) as share_save,
            SUM(views) as views,
            AVG(cpm) as cpm
            FROM endorse
            WHERE id_campaign = '{$idParent}' AND link_upload != '' AND status = 'Aktif'");
        $itemDetail = $itemDetail[0] ?? [];

        $item = $this->CI->mymodel->selectWithQuery("SELECT
            SUM(likes) as likes,
            SUM(comment) as comment,
            SUM(share_save) as share_save,
            SUM(views) as views,
            AVG(cpm) as cpm,
            SUM(likes_after) as likes_after,
            SUM(comment_after) as comment_after,
            SUM(share_save_after) as share_save_after,
            SUM(views_after) as views_after,
            AVG(cpm_after) as cpm_after,
            SUM(likes_before) as likes_before,
            SUM(comment_before) as comment_before,
            SUM(share_save_before) as share_save_before,
            SUM(views_before) as views_before,
            AVG(cpm_before) as cpm_before
            FROM endorse_logs
            WHERE id_campaign = '{$idParent}'");
        $item = $item[0] ?? [];

        $dt = [
            'id_campaign' => $idParent,
            'total_cost' => doubleval($itemDetail['total_cost'] ?? 0),
            'date' => $today,
        ];
        foreach ($item as $key => $value) {
            $dt[$key] = doubleval($value);
        }

        $dtt = [];
        foreach ($itemDetail as $key => $value) {
            $dtt[$key] = doubleval($value);
        }

        $summary = $this->CI->mymodel->selectWithQuery("SELECT COUNT(id) as count FROM endorse WHERE id_campaign = '{$idParent}'");
        $dtt['count_endorse'] = intval($summary[0]['count'] ?? 0);
        $dt['ce_now'] = $dtt['count_endorse'];

        $summary = $this->CI->mymodel->selectWithQuery("SELECT COUNT(id) as count FROM endorse WHERE id_campaign = '{$idParent}' AND status = 'Aktif'");
        $dtt['count_endorse_active'] = intval($summary[0]['count'] ?? 0);
        $dt['ce_active_now'] = $dtt['count_endorse_active'];

        $summary = $this->CI->mymodel->selectWithQuery("SELECT COUNT(id) as count FROM endorse WHERE id_campaign = '{$idParent}' AND status = 'Aktif' AND link_upload != ''");
        $dtt['count_endorse_processed'] = intval($summary[0]['count'] ?? 0);
        $dt['ce_processed_now'] = $dtt['count_endorse_processed'];

        $summary = $this->CI->mymodel->selectWithQuery("SELECT COUNT(DISTINCT influencer) as count FROM endorse WHERE id_campaign = '{$idParent}'");
        $dtt['count_influencer'] = intval($summary[0]['count'] ?? 0);
        $dt['ci_now'] = $dtt['count_influencer'];

        $summary = $this->CI->mymodel->selectWithQuery("SELECT COUNT(DISTINCT influencer) as count FROM endorse WHERE id_campaign = '{$idParent}' AND status = 'Aktif'");
        $dtt['count_influencer_active'] = intval($summary[0]['count'] ?? 0);
        $dt['ci_active_now'] = $dtt['count_influencer_active'];

        $summary = $this->CI->mymodel->selectWithQuery("SELECT COUNT(DISTINCT influencer) as count FROM endorse WHERE id_campaign = '{$idParent}' AND status = 'Aktif' AND link_upload != ''");
        $dtt['count_influencer_processed'] = intval($summary[0]['count'] ?? 0);
        $dt['ci_processed_now'] = $dtt['count_influencer_processed'];

        $dt['ci_before'] = doubleval($queryYesterday['ci_now'] ?? 0);
        $dt['ci_active_before'] = doubleval($queryYesterday['ci_active_now'] ?? 0);
        $dt['ci_processed_before'] = doubleval($queryYesterday['ci_processed_now'] ?? 0);
        $dt['ce_before'] = doubleval($queryYesterday['ce_now'] ?? 0);
        $dt['ce_active_before'] = doubleval($queryYesterday['ce_active_now'] ?? 0);
        $dt['ce_processed_before'] = doubleval($queryYesterday['ce_processed_now'] ?? 0);

        $dt['ci_after'] = $dt['ci_now'];
        $dt['ci_active_after'] = $dt['ci_active_now'];
        $dt['ci_processed_after'] = $dt['ci_processed_now'];
        $dt['ce_after'] = $dt['ce_now'];
        $dt['ce_active_after'] = $dt['ce_active_now'];
        $dt['ce_processed_after'] = $dt['ce_processed_now'];

        $dt['ci_now'] = $dt['ci_after'] - $dt['ci_before'];
        $dt['ci_active_now'] = $dt['ci_active_after'] - $dt['ci_active_before'];
        $dt['ci_processed_now'] = $dt['ci_processed_after'] - $dt['ci_processed_before'];
        $dt['ce_now'] = $dt['ce_after'] - $dt['ce_before'];
        $dt['ce_active_now'] = $dt['ce_active_after'] - $dt['ce_active_before'];
        $dt['ce_processed_now'] = $dt['ce_processed_after'] - $dt['ce_processed_before'];

        $campaign = $this->CI->mymodel->selectDataOne('endorse_campaign', ['id' => $idParent]);
        if (!empty($campaign['brand'])) {
            $dt['brand'] = strval($campaign['brand']);
        }

        $dtTmp = [];
        foreach ($dt as $key => $value) {
            $dtTmp[$key] = strval($value);
        }
        $dt = $dtTmp;

        if (!empty($query['id'])) {
            $dt['updated_at'] = date('Y-m-d H:i:s');
            if ($userId > 0) {
                $dt['updated_by'] = strval($userId);
            }
            $this->CI->db->update('endorse_campaign_logs', $dt, ['id' => intval($query['id'])]);
        } else {
            $dt['created_at'] = date('Y-m-d H:i:s');
            if ($userId > 0) {
                $dt['created_by'] = strval($userId);
            }
            $this->CI->db->insert('endorse_campaign_logs', $dt);
        }

        $dttTmp = [];
        foreach ($dtt as $key => $value) {
            $dttTmp[$key] = strval($value);
        }
        $dtt = $dttTmp;
        $dtt['updated_at'] = date('Y-m-d H:i:s');
        if ($userId > 0) {
            $dtt['updated_by'] = strval($userId);
        }

        $this->CI->db->update('endorse_campaign', $dtt, ['id' => $idParent]);
        return true;
    }
}
