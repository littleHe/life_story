<?php
namespace app\controller\Admin;

use think\facade\Db;

/**
 * 后台控制台（首页看板）
 */
class IndexController extends AdminBase
{
    /** GET /admin-api/stat —— 概览数字 + 近7天趋势 */
    public function stat()
    {
        $today = date('Y-m-d');

        // ---- 概览 ----
        $overview = [
            'user_total'      => (int) Db::name('ls_user')->count(),
            'user_today'      => (int) Db::name('ls_user')->whereTime('created_at', 'today')->count(),
            'project_total'   => (int) Db::name('ls_project')->count(),
            'project_editable'=> (int) Db::name('ls_project')->where('status', 'EDITABLE')->count(),
            'project_locked'  => (int) Db::name('ls_project')->where('status', 'UNPAID_LOCKED')->count(),
            'chapter_total'   => (int) Db::name('ls_chapter')->count(),
            'chapter_today'   => (int) Db::name('ls_chapter')->whereTime('created_at', 'today')->count(),
            'code_total'      => (int) Db::name('ls_redemption_code')->count(),
            'code_bound'      => (int) Db::name('ls_redemption_code')->where('status', 'BOUND')->count(),
            'code_verify'     => (int) Db::name('ls_verification_log')->count(),
            'code_verify_today' => (int) Db::name('ls_verification_log')->whereTime('created_at', 'today')->count(),
            'apilog_total'    => (int) Db::name('ls_api_log')->count(),
            'slide_total'     => (int) Db::name('ls_slide')->count(),
        ];

        // ---- 近7天趋势 ----
        $week = [];
        for ($i = 6; $i >= 0; $i--) {
            $day   = date('Y-m-d', strtotime("-{$i} day"));
            $start = $day . ' 00:00:00';
            $end   = $day . ' 23:59:59';

            $week[] = [
                'date'    => $day,
                'user'    => (int) Db::name('ls_user')->whereBetweenTime('created_at', $start, $end)->count(),
                'project' => (int) Db::name('ls_project')->whereBetweenTime('created_at', $start, $end)->count(),
                'chapter' => (int) Db::name('ls_chapter')->whereBetweenTime('created_at', $start, $end)->count(),
            ];
        }

        // ---- 最新动态 ----
        $latestProjects = Db::name('ls_project')
            ->alias('p')
            ->leftJoin('ls_user u', 'u.id = p.user_id')
            ->field('p.id,p.name,p.real_name,p.status,p.created_at,u.nickname')
            ->order('p.id', 'desc')
            ->limit(8)
            ->select()
            ->toArray();

        return $this->ok([
            'today'          => $today,
            'overview'       => $overview,
            'week'           => $week,
            'latestProjects' => $latestProjects,
            'admin'          => [
                'nickname' => $this->admin()['nickname'] ?? '',
                'last'     => date('Y-m-d H:i:s'),
            ],
        ]);
    }
}
