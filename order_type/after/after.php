<?php

namespace App\Http\Controllers\Simple;


use DB;
use Log;
use Auth;
use App\Model\Simple\OrderType;
use App\Model\Simple\Order as OrderModel;


class Order extends AdminController
{
    // 数据库存在的状态批量更新
    public function bat_update_type($request)
    {

        $name = $request->input('name');
        $code = $request->input('code');
        $text = $request->input('text');
        $row = explode("\n", $text);

        error_log("\n " . date('Y-m-d H:i:s') . ' ' . Auth::user()->name . ' ' . $name, 3, '/tmp/batlog');
        $_order_type = OrderType::select('id', 'code')->pluck('id', 'code');

        if (!isset($_order_type[$code])) {
            return response()->json(['msg' => '没有这个状态']);
        }

        $_type_id = $_order_type[$code];

        $msg = [];

        // 批量更新已库存,有效单,退件,重出单,确认中（都是通过订单id修改，代码逻辑完全一样）
        if (in_array($code, ['diaohuo', 'youxiao', 'tuijian', 'chongchu', 'queren'])) {
            $orders = OrderModel::select('id','type_id')->whereIn('id', $row)->get();
            $allow = [];
            foreach ($orders as $order) {
                $allow[$order->id] = $order->type_id;
            }

            $updateId = [];
            foreach ($row as $value) {
                $id = trim($value);
                if (isset($allow[$id])) {
                    $check_content_type = OrderType::getFromCached()->firstwhere('id', $allow[$id])->code;
                    if (OrderType::checkTypeAuth($code, $check_content_type)) {
                        $updateId[] = $id;
                        $msg[] = $id . ': 更新成功！';
                    } else {
                        $msg[] = $id . ': 更新失败!!!!';
                    }
                } else {
                    $msg[] = $id . ': 订单不存在';
                }
            }

            if (!empty($updateId)) {
                OrderModel::whereIn('id', $updateId)->update(['type_id' => $_type_id]);
            }
        }

        // 批量发货改问题件（通过物流单号修改）
        if (in_array($code, ['wentijian', 'shoukuang'])) {
            $orders = OrderModel::select('id','tcode','type_id')->whereIn('tcode', $row)->get();
            $allow = [];
            foreach ($orders as $order) {
                $allow[trim($order->tcode)] = $order->type_id;
            }

            $updateId = [];
            foreach ($row as $value) {
                $msg_part = ': 快递单不存在';
                preg_match('/[a-zA-Z0-9]+$/', trim($value), $matches);
                list($tcode) = $matches;
                if (isset($allow[$tcode])) {
                    $msg_part = ': 更新失败!!!!';
                    $check_content_type = OrderType::getFromCached()->firstwhere('id', $allow[$tcode])->code;
                    if (OrderType::checkTypeAuth($code, $check_content_type)) {
                        $updateId[] = $tcode;
                        $msg_part = ': 更新成功！';
                    }
                }
                $msg[] = $tcode . $msg_part;
            }

            if (!empty($updateId)) {
                OrderModel::whereIn('tcode', $updateId)->update(['type_id' => $_type_id]);
            }
        }

        // 批量更新已出仓（不同的角色更改的权限不同）
        if ($code == "chucang") {
            $ids = [];
            foreach ($row as $value) {
                $id = trim($value);
                if (empty($id)) continue;

                //过滤发过货的订单（发过货的单不能再改为已出仓）
                $is_fahuo = OrderLog::where('order_id', $id)->where('type_id', $_order_type['fahuo'])->first();
                if ($is_fahuo) continue;

                $ids[] = $id;
            }

            $orders = OrderModel::select('id','type_id')->whereIn('id', $ids)->get();
            $allow = [];
            foreach ($orders as $order) {
                $allow[$order->id] = $order->type_id;
            }

            foreach ($ids as $id) {
                $msg_part = ': 订单不存在';
                if (isset($allow[$id])) {
                    $msg_part = ': 更新失败!!!!';
                    if (Auth::user()->hasRole(['exploit.logistics', 'gendan.admin'])) {
                        if ($allow[$id] == $_order_type['fahuo'] || $allow[$id] == $_order_type['qianshou'] || $allow[$id] == $_order_type['juqian']) {
                            $msg_part = ': 更新成功!';
                            OrderModel::where('id', $id)->update(['type_id' => $_type_id]);
                        }
                    } else {
                        $check_content_type = OrderType::getFromCached()->firstwhere('id', $allow[$id])->code;
                        if (OrderType::checkTypeAuth($code, $check_content_type)) {
                            $msg_part = ': 更新成功!';
                            OrderModel::where('id', $id)->update(['type_id' => $_type_id]);
                        }
                    }
                }
                $msg[] = $id . $msg_part;
            }
        }

        // 批量更新已发货和快递单号（发货后需要更新库存）
        if ($code == "fahuo") {
            $ids = [];
            foreach ($row as $value) {
                if (empty($value)) continue;

                list($id, $tcode) = preg_split('/[\s,]+/', $value);
                if (empty($id) || empty($tcode)) continue;

                preg_match('/[a-zA-Z0-9]+/', trim($tcode), $matches);
                list($tcode) = $matches;
                $ids[] = array('id' => $id, 'tcode' => $tcode);
            }

            $orders = OrderModel::select('id','type_id','note')->whereIn('id', array_pluck($ids, 'id'))->get();
            $allow = [];
            $note = [];
            foreach ($orders as $order) {
                $allow[$order->id] = $order->type_id;
                $note[$order->id] = $order->note;
            }

            foreach ($ids as $value) {
                $id = $value['id'];
                if (isset($allow[$id])) {
                    $check_content_type = OrderType::getFromCached()->firstwhere('id', $allow[$id])->code;
                    if (OrderType::checkTypeAuth($code, $check_content_type)) {
                        OrderModel::where('id', $id)->update(['type_id' => $_type_id, 'tcode' => $value['tcode'],
                            'note' => $note[$id] . ' || 发货时间：' . date("Y-m-d H:i:s", time())]);
                        /********jqw 2017-12-21 14:05 发货状态，减已库存和实际库存****************/
                        $this->selectProductSku($id, $_type_id);
                        /********jqw 2017-12-21 14:05 发货状态，减已库存和实际库存****************/
                        $msg[] = $id . ': 更新成功!';
                    } else {
                        $msg[] = $id . ': 更新失败!!!!';
                    }
                } else {
                    $msg[] = $id . ': 订单不存在';
                }
            }
            if (!empty($this->log['已发货'])) {
                file_put_contents(storage_path('logs/yifahuo.txt'), implode("\n", $this->log['已发货']) . "\n", FILE_APPEND);
            }
        }

        // 批量更新已签收（不同收款方式的单修改的逻辑不同）
        if ($code == "qianshou") {
            $orders = OrderModel::select('id','tcode','type_id','payMode')->whereIn('tcode', $row)->get();
            $allow = [];
            $allow_paid = [];
            foreach ($orders as $order) {
                $order_tcode = trim($order->tcode);
                if ($order->payMode == 7) {
                    $allow_paid[$order_tcode] = $order->type_id;
                } else {
                    $allow[$order_tcode] = $order->type_id;
                }
            }

            $updateId = [];
            $updateIdByPaid = [];
            foreach ($row as $value) {
                $msg_part = ': 订单不存在';
                preg_match('/[a-zA-Z0-9]+/', trim($value), $matches);
                list($tcode) = $matches;
                if (isset($allow[$tcode])) {
                    $msg_part = ': 更新失败!!!!';
                    if ($allow[$tcode] == $_order_type['fahuo']) {
                        $updateId[] = $tcode;
                        $msg_part = ': 更新成功！';
                    }
                }

                if (isset($allow_paid[$tcode])) {
                    if ($allow_paid[$tcode] == $_order_type['fahuo']) {
                        $updateIdByPaid[] = $tcode;
                        $msg_part = ': 更新成功！';
                    }
                }
                $msg[] = $tcode . $msg_part;
            }

            if (!empty($updateId)) {
                OrderModel::whereIn('tcode', $updateId)->update(['type_id' => $_type_id]);
            }
            if (!empty($updateIdByPaid)) {
                // 在线支付单签收直接改为收款
                OrderModel::whereIn('tcode', $updateIdByPaid)->update(['type_id' => $_order_type['shoukuang']]);
            }
        }

        // 批量更新拒签（不同的角色更改的权限不同）
        if ($code == "juqian") {
            $orders = OrderModel::select('id','tcode','type_id')->whereIn('tcode', $row)->get();
            $allow = [];
            foreach ($orders as $order) {
                $allow[trim($order->tcode)] = $order->type_id;
            }

            $updateId = [];
            foreach ($row as $value) {
                $msg_part = ': 快递单不存在';
                preg_match('/[a-zA-Z0-9]+$/', trim($value), $matches);
                list($tcode) = $matches;
                if (isset($allow[$tcode])) {
                    $msg_part = ': 更新失败!!!!';
                    if (Auth::user()->hasRole(['money.admin'])) {
                        if ($allow[$tcode] == $_order_type['fahuo'] || $allow[$tcode] == $_order_type['shoukuang'] || $allow[$tcode] == $_order_type['qianshou']) {
                            $updateId[] = $tcode;
                            $msg_part = ': 更新成功！';
                        }
                    } else {
                        $check_content_type = OrderType::getFromCached()->firstwhere('id', $allow[$tcode])->code;
                        if (OrderType::checkTypeAuth($code, $check_content_type)) {
                            $updateId[] = $tcode;
                            $msg_part = ': 更新成功！';
                        }
                    }
                }
                $msg[] = $tcode . $msg_part;
            }

            if (!empty($updateId)) {
                // 拒签的在线支付单需要将收款金额改为0
                OrderModel::whereIn('tcode', $updateId)->where('payMode', '<>', 1)->update(['type_id' => $_type_id, 'sprice' => 0]);
                OrderModel::whereIn('tcode', $updateId)->where('payMode', 1)->update(['type_id' => $_type_id]);
            }
        }

        return response()->json(['msg' => implode("\n", $msg)]);
    }
}