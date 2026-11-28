<?php
/**
 * 前台：订单
 */
namespace app\controller;

use think\Db;
use think\Response;

class Order extends Front
{
    public function index(): Response
    {
        $user = $this->user();
        $list = Db::table('order')->where('user_id', $user['id'])->order('id', 'desc')->paginate(15, $this->pageNo());
        foreach ($list['data'] as &$row) {
            $row['items'] = Db::table('order_item')->where('order_id', $row['id'])->select();
            $row['invoice'] = Db::table('invoice')->where('order_id', $row['id'])->find();
        }
        unset($row);
        return $this->view('order_list', compact('list'));
    }

    public function detail(int $id): Response
    {
        $user = $this->user();
        $order = Db::table('order')->where(['id' => $id, 'user_id' => $user['id']])->find();
        if (!$order) return $this->notFound('订单不存在');
        $items = Db::table('order_item')->where('order_id', $id)->select();
        $invoice = Db::table('invoice')->where('order_id', $id)->find();
        return $this->view('order_detail', compact('order', 'items', 'invoice'));
    }
}
