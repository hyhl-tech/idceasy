<?php
/**
 * 后台仪表盘
 */
namespace app\controller\admin;

use think\Db;
use think\Response;

class Dashboard extends Base
{
    public function index(): Response
    {
        $monthStart = date('Y-m-01 00:00:00');
        $today = date('Y-m-d 00:00:00');
        $unpaidQ = Db::table('invoice')->where('status', 'unpaid');
        $stats = [
            'revenue_month' => (float)Db::table('transaction')->where('status', 'success')
                ->where('amount', '>', 0)->where('invoice_id', '>', 0)
                ->whereRaw('created_at >= ?', [$monthStart])->sum('amount'),
            'orders_today'  => Db::table('order')->whereRaw('created_at >= ?', [$today])->count(),
            'clients'       => Db::table('user')->where('status', 1)->count(),
            'unpaid_total'  => (float)$unpaidQ->sum('total') - (float)Db::table('invoice')->where('status', 'unpaid')->sum('paid'),
            'tickets_open'  => Db::table('ticket')->whereIn('status', ['open', 'customer_reply'])->count(),
            'hosts_active'  => Db::table('host')->whereIn('status', ['active', 'suspended'])->count(),
            'domains'       => Db::table('domain')->where('status', 'active')->count(),
            'pending_orders'=> Db::table('order')->whereIn('status', ['paid', 'pending'])->count(),
        ];

        $rows = Db::select('SELECT DATE_FORMAT(created_at, "%Y-%m") AS m, SUM(amount) AS s FROM '
            . Db::prefix() . 'transaction WHERE status = "success" AND amount > 0 AND invoice_id > 0'
            . ' AND created_at >= ? GROUP BY m ORDER BY m', [date('Y-m-01', strtotime('-11 months'))]);
        $map = array_column($rows, 's', 'm');
        $revenue = [];
        $max = 1;
        for ($i = 11; $i >= 0; $i--) {
            $m = date('Y-m', strtotime('-' . $i . ' months'));
            $v = (float)($map[$m] ?? 0);
            $max = max($max, $v);
            $revenue[] = ['month' => $m, 'amount' => $v];
        }
        foreach ($revenue as &$r) $r['percent'] = round($r['amount'] / $max * 100, 1);
        unset($r);

        $recentOrders = Db::table('order')->order('id', 'desc')->limit(8)->select();
        foreach ($recentOrders as &$o) {
            $o['user'] = Db::table('user')->where('id', $o['user_id'])->find();
        }
        unset($o);
        $recentTickets = Db::table('ticket')->order('id', 'desc')->limit(6)->select();
        foreach ($recentTickets as &$t) {
            $t['user'] = Db::table('user')->where('id', $t['user_id'])->find();
        }
        unset($t);
        $expiringDomains = Db::table('domain')->where('status', 'active')
            ->whereRaw('expire_date <= ?', [date('Y-m-d H:i:s', strtotime('+30 days'))])
            ->order('expire_date')->limit(6)->select();
        $expiringHosts = Db::table('host')->whereIn('status', ['active', 'suspended'])
            ->whereRaw('next_due <= ?', [date('Y-m-d H:i:s', strtotime('+14 days'))])
            ->order('next_due')->limit(6)->select();
        $servers = Db::table('server')->where('status', 1)->order('id')->select();
        foreach ($servers as &$s) {
            $s['load'] = Db::table('host')->where('server_id', $s['id'])->whereIn('status', ['active', 'suspended'])->count();
        }
        unset($s);

        return $this->view('dashboard', [
            'stats' => $stats, 'revenue' => $revenue, 'recentOrders' => $recentOrders,
            'recentTickets' => $recentTickets, 'expiringDomains' => $expiringDomains,
            'expiringHosts' => $expiringHosts, 'servers' => $servers, 'title' => '仪表盘',
        ]);
    }
}
