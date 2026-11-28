<?php
/**
 * 后台：经营报表
 */
namespace app\controller\admin;

use think\Db;
use think\Response;

class Report extends Base
{
    protected string $perm = 'report';

    public function index(): Response
    {
        $year = $this->request->int('year', (int)date('Y'));
        if ($year < 2000 || $year > 2100) $year = (int)date('Y');

        $monthly = [];
        for ($m = 1; $m <= 12; $m++) {
            $monthly[$m] = ['month' => $m, 'amount' => 0.0, 'orders' => 0];
        }
        $rows = Db::select('SELECT MONTH(created_at) AS m, SUM(amount) AS s FROM ' . Db::prefix()
            . 'transaction WHERE status = "success" AND amount > 0 AND invoice_id > 0 AND YEAR(created_at) = ? GROUP BY m', [$year]);
        foreach ($rows as $r) {
            if (isset($monthly[(int)$r['m']])) $monthly[(int)$r['m']]['amount'] = (float)$r['s'];
        }
        $orderRows = Db::select('SELECT MONTH(created_at) AS m, COUNT(*) AS c FROM ' . Db::prefix()
            . 'order WHERE YEAR(created_at) = ? GROUP BY m', [$year]);
        foreach ($orderRows as $r) {
            if (isset($monthly[(int)$r['m']])) $monthly[(int)$r['m']]['orders'] = (int)$r['c'];
        }
        $max = 1;
        foreach ($monthly as $m) $max = max($max, $m['amount']);
        foreach ($monthly as &$m) $m['percent'] = round($m['amount'] / $max * 100, 1);
        unset($m);

        $revenue = (float)Db::table('transaction')->where('status', 'success')->where('amount', '>', 0)
            ->where('invoice_id', '>', 0)->whereRaw('YEAR(created_at) = ?', [$year])->sum('amount');
        $refunds = abs((float)Db::table('transaction')->where('gateway', 'refund')
            ->whereRaw('YEAR(created_at) = ?', [$year])->sum('amount'));
        $orders = Db::table('order')->whereRaw('YEAR(created_at) = ?', [$year])->count();
        $clients = Db::table('user')->whereRaw('YEAR(created_at) = ?', [$year])->count();
        $tax = (float)Db::table('invoice')->where('status', 'paid')->whereRaw('YEAR(created_at) = ?', [$year])->sum('tax');

        $byStatus = Db::select('SELECT status, COUNT(*) AS c, SUM(total) AS s FROM ' . Db::prefix()
            . 'order WHERE YEAR(created_at) = ? GROUP BY status', [$year]);

        $topProducts = Db::select('SELECT oi.product_id, p.name, COUNT(*) AS c, SUM(oi.amount) AS s FROM '
            . Db::prefix() . 'order_item oi LEFT JOIN ' . Db::prefix() . 'product p ON p.id = oi.product_id'
            . ' LEFT JOIN ' . Db::prefix() . 'order o ON o.id = oi.order_id'
            . ' WHERE YEAR(o.created_at) = ? AND oi.product_id > 0 GROUP BY oi.product_id, p.name ORDER BY s DESC LIMIT 10', [$year]);

        $topClients = Db::select('SELECT o.user_id, u.email, u.name, COUNT(*) AS c, SUM(o.total) AS s FROM '
            . Db::prefix() . 'order o LEFT JOIN ' . Db::prefix() . 'user u ON u.id = o.user_id'
            . ' WHERE YEAR(o.created_at) = ? GROUP BY o.user_id, u.email, u.name ORDER BY s DESC LIMIT 10', [$year]);

        $gateways = Db::select('SELECT gateway, COUNT(*) AS c, SUM(amount) AS s FROM ' . Db::prefix()
            . 'transaction WHERE status = "success" AND amount > 0 AND YEAR(created_at) = ? GROUP BY gateway ORDER BY s DESC', [$year]);

        return $this->view('report', compact(
            'year', 'monthly', 'revenue', 'refunds', 'orders', 'clients', 'tax',
            'byStatus', 'topProducts', 'topClients', 'gateways'
        ));
    }
}
