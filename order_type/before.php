<?php

namespace App\Http\Controllers\Simple;

use DB;
use Log;
use Auth;

class Order extends AdminController
{
    //数据库存在的状态批量更新
    public function bat_update_type($request)
    {

        $name = $request->input('name');
        $code = $request->input('code');
        $text = $request->input('text');
        $row = explode("\n", $text);

        error_log("\n " . date('Y-m-d H:i:s') . ' ' . Auth::user()->name . ' ' . $name, 3, '/tmp/batlog');

        $_order_type = \App\Model\Simple\OrderType::all();
        foreach ($_order_type as $value) {
            $types[$value->code] = array(
                'id' => $value->id, 'name' => $value->name,
            );
        }

        if (!isset($types[$code]) && ($code != 'update_tcode')) {
            return response()->json(['msg' => '没有这个状态']);
        }

        if (isset($types[$code])) {
            $_type_id = $types[$code]['id'];
        }
        $msg = [];

        //*****DY*****批量更新已库存
        if ($code == "diaohuo") {

            $orders = \App\Model\Simple\Order::select(DB::raw('id,type_id'))->whereIn('id', $row)->get();
            $allow = [];
            foreach ($orders as $order) {
                $allow[$order->id] = $order->type_id;
            }

            $updateId = [];
            foreach ($row as $value) {
                $id = trim($value);
                if (isset($allow[$id])) {
                    if ($allow[$id] == $types['xiadan']['id'] || $allow[$id] == $types['youxiao']['id']) {
                        $updateId[] = $id;
                        $msg[] = $id . ': 更新成功！';
                    } else {
                        $msg[] = $id . ': 更新失败!!!!';
                    }
                } else {
                    $msg[] = $id . ': 快递单不存在';
                }
            }

            if (!empty($updateId)) {
                \App\Model\Simple\Order::whereIn('id', $updateId)->update(['type_id' => $_type_id]);
            }
        }
        //*****DY*****批量更新已出仓
        if ($code == "chucang") {
            $ids = [];
            foreach ($row as $value) {
                if (empty($value)) {
                    continue;
                }

                list($id) = preg_split('/[\s,\t]+/', $value);
                if (empty($id)) {
                    continue;
                }

                $order = \App\Model\Simple\Order::where('id',$value)->first(['payMode']);
                if ($order->payMode == 7){
                    //是否发货过
                    $is_fahuo = \App\Model\Simple\OrderLog::where('order_id',$value)->where('type_id',3)->first();
                    if ($is_fahuo){
                        continue;
                    }
                }

                $ids[] = array('id' => $id);
            }

            $orders = \App\Model\Simple\Order::select(DB::raw('id,type_id'))->whereIn('id', array_pluck($ids, 'id'))->get();
            $allow = [];
            foreach ($orders as $order) {
                $allow[$order->id] = $order->type_id;
            }

            foreach ($ids as $value) {
                $id = $value['id'];

                if (isset($allow[$id])) {
                    if(Auth::user()->hasRole(['exploit.logistics','gendan.admin'])){
                        if ($allow[$id] == $types['fahuo']['id'] || $allow[$id] == $types['qianshou']['id'] || $allow[$id] == $types['juqian']['id']) {
                            \App\Model\Simple\Order::where('id', $id)->update(['type_id' => $_type_id]);
                            $msg[] = $id . ': 更新成功!';
                        } else {
                            $msg[] = $id . ': 更新失败!!!!';
                        }
                    }else{
                        if ($allow[$id] == $types['diaohuo']['id'] || $allow[$id] == $types['yicaigou']['id'] || $allow[$id] == $types['youxiao']['id'] || $allow[$id] == $types['caigou']['id']) {
                            \App\Model\Simple\Order::where('id', $id)->update(['type_id' => $_type_id]);
                            $msg[] = $id . ': 更新成功!';
                        } else {
                            $msg[] = $id . ': 更新失败!!!!';
                        }
                    }

                } else {
                    $msg[] = $id . ': 订单不存在';
                }
            }
        }
        //*****DY*****批量更新已发货和快递单号
        if ($code == "fahuo") {
            $ids = [];
            foreach ($row as $value) {
                if (empty($value)) {
                    continue;
                }

                list($id, $tcode) = preg_split('/[\s,\t]+/', $value);
                if (empty($id) || empty($tcode)) {
                    continue;
                } else {
                    $tcode = trim($tcode);
                    preg_match('/[a-zA-Z0-9]+/', $tcode, $matches);
                    list($tcode) = $matches;
                    $ids[] = array('id' => $id, 'tcode' => $tcode);
                }
            }

            $orders = \App\Model\Simple\Order::select(DB::raw('id,type_id,note'))->whereIn('id', array_pluck($ids, 'id'))->get();
            $allow = [];
            $note = [];
            foreach ($orders as $order) {
                $allow[$order->id] = $order->type_id;
                $note[$order->id] = $order->note;
            }

            foreach ($ids as $value) {
                $id = $value['id'];
                $date = date("Y-m-d H:i:s", time());
                $tcode = $value['tcode'];
                if (isset($allow[$id])) {
                    if ($allow[$id] == $types['chucang']['id'] || $allow[$id] == $types['chongchu']['id']) {
                        \App\Model\Simple\Order::where('id', $id)->update(['type_id' => $_type_id, 'tcode' => $tcode,
                            'note' => $note[$id] . ' || 发货时间：' . $date]);
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

        //*****DY*****批量更新快递单号
        if ($code == "update_tcode") {
            $ids = [];
            foreach ($row as $value) {
                if (empty($value)) {
                    continue;
                }

                list($id, $tcode) = preg_split('/[\s,\t]+/', $value);
                if (empty($id) || empty($tcode)) {
                    continue;
                } else {
                    $tcode = trim($tcode);
                    preg_match('/[a-zA-Z0-9]+/', $tcode, $matches);
                    list($tcode) = $matches;
                    $ids[] = array('id' => $id, 'tcode' => $tcode);
                }
            }

            $orders = \App\Model\Simple\Order::select(DB::raw('id,type_id,note'))->whereIn('id', array_pluck($ids, 'id'))->get();
            $allow = [];
            $note = [];
            foreach ($orders as $order) {
                $allow[$order->id] = $order->type_id;
                $note[$order->id] = $order->note;
            }

            foreach ($ids as $value) {
                $id = $value['id'];
                $date = date("Y-m-d H:i:s", time());
                $tcode = $value['tcode'];

                $text = $id . ': 未付款单不能添加物流单号';
                if (isset($allow[$id])) {

                    //判断订单是否是银行卡支付
                    $order_one = \App\Model\Simple\Order::select(['payMode', 'type_id'])->where('id', $id)->first();
                    if (!$order_one) {
                        $msg[] = $id . ': 订单不存在';
                        continue;
                    }

                    //银行卡支付且是已收款单直接添加物流单号
                    if ($order_one->payMode != 1 && $order_one->type_id == 9) {
                        \App\Model\Simple\Order::where('id', $id)->update(['tcode' => $tcode,
                            'note' => $note[$id] . ' 银行卡支付添加物流单号时间：' . $date]);
                        $msg[] = $id . ': 更新成功';
                        continue;
                    }
                }

                $msg[] = $text;
            }
        }

        //*****DY*****批量更新已签收
        if ($code == "qianshou") {
            $orders = \App\Model\Simple\Order::select(DB::raw('id,tcode,type_id,payMode'))->whereIn('tcode', $row)->get();
            $allow = [];
            $allow_shoukuan = [];
            foreach ($orders as $order) {
                $order_tcode = trim($order->tcode);
                if ($order->payMode == 7){
                    $allow_shoukuan[$order_tcode] = $order->type_id;
                }else{
                    $allow[$order_tcode] = $order->type_id;
                }

            }

            $updateId = [];
            $updateIdByShoukuan = [];
            foreach ($row as $value) {
                $tcode = trim($value);
                preg_match('/[a-zA-Z0-9]+/', $tcode, $matches);
                list($tcode) = $matches;
                if (isset($allow[$tcode])) {
                    if ($allow[$tcode] == $types['fahuo']['id']) {
                        $updateId[] = $tcode;
                        $msg[] = $tcode . ': 更新成功！';
                    } else {
                        $msg[] = $tcode . ': 更新失败!!!!';
                    }
                }

                if (isset($allow_shoukuan[$tcode])) {
                    if ($allow_shoukuan[$tcode] == $types['fahuo']['id']) {
                        $updateIdByShoukuan[] = $tcode;
                        $msg[] = $tcode . ': 更新成功！';
                    } else {
                        $msg[] = $tcode . ': 更新失败!!!!';
                    }
                }


            }

            if (!empty($updateId)) {
                \App\Model\Simple\Order::whereIn('tcode', $updateId)->update(['type_id' => $_type_id]);
            }
            if (!empty($updateIdByShoukuan)) {
                \App\Model\Simple\Order::whereIn('tcode', $updateIdByShoukuan)->update(['type_id' => 9]);
            }
        }
        //*****DY*****批量更新有效单
        if ($code == "youxiao") {
            //$orders = \App\Model\Purchas\PurchasProduct::select('id')->whereIn('order_id',$row)->get();
            $orders = \App\Model\Simple\Order::select(DB::raw('id,type_id'))->whereIn('id', $row)->get();
            $allow = [];
            foreach ($orders as $order) {
                $allow[$order->id] = $order->type_id;
                //$allow[] = $order->id;
            }

            $updateId = [];
            foreach ($row as $value) {
                $id = trim($value);
                if (isset($allow[$id])) {
                    if ($allow[$id] == $types['xiadan']['id']) {
                        $updateId[] = $id;
                        $msg[] = $id . ': 更新成功！';
                    } else {
                        $msg[] = $id . ': 更新失败!!!!';
                    }
                } else {
                    $msg[] = $id . ': 快递单不存在';
                }
            }

            if (!empty($allow)) {
                //\App\Model\Purchas\PurchasProduct::whereIn('id', $allow)->update(['status' => '2']);
                \App\Model\Simple\Order::whereIn('id', $updateId)->update(['type_id' => $_type_id]);
            }
        }
        //*****DY*****批量更新拒签
        if ($code == "juqian") {

            $orders = \App\Model\Simple\Order::select(DB::raw('id,tcode,type_id'))->whereIn('tcode', $row)->get();
            $allow = [];
            foreach ($orders as $order) {
                $order_tcode = trim($order->tcode);
                $allow[$order_tcode] = $order->type_id;
            }

            $updateId = [];
            foreach ($row as $value) {
                $tcode = trim($value);
                preg_match('/[a-zA-Z0-9]+$/', $tcode, $matches);
                list($tcode) = $matches;
                if (isset($allow[$tcode])) {
                    if (Auth::user()->hasRole(['money.admin'])){
                        if ($allow[$tcode] == $types['fahuo']['id'] || $allow[$tcode] == $types['shoukuang']['id'] || $allow[$tcode] == $types['qianshou']['id']) {
                            $updateId[] = $tcode;
                            $msg[] = $tcode . ': 更新成功！';
                        } else {
                            $msg[] = $tcode . ': 更新失败!!!!';
                        }
                    }else{
                        if ($allow[$tcode] == $types['fahuo']['id'] || $allow[$tcode] == $types['qianshou']['id']) {
                            $updateId[] = $tcode;
                            $msg[] = $tcode . ': 更新成功！';
                        } else {
                            $msg[] = $tcode . ': 更新失败!!!!';
                        }
                    }

                } else {
                    $msg[] = $tcode . ': 快递单不存在';
                }
            }

            if (!empty($updateId)) {
                \App\Model\Simple\Order::whereIn('tcode', $updateId)->where('payMode',7)->update(['type_id' => $_type_id,'sprice'=>0]);
                \App\Model\Simple\Order::whereIn('tcode', $updateId)->where('payMode','<>',7)->update(['type_id' => $_type_id]);
            }
        }
        //*****DY*****批量更新退件
        if ($code == "tuijian") {

            $orders = \App\Model\Simple\Order::select(DB::raw('id,tcode,type_id'))->whereIn('tcode', $row)->get();
            $allow = [];
            foreach ($orders as $order) {
                $order_tcode = trim($order->tcode);
                $allow[$order_tcode] = $order->type_id;
            }

            $updateId = [];
            foreach ($row as $value) {
                $tcode = trim($value);
                preg_match('/[a-zA-Z0-9]+$/', $tcode, $matches);
                list($tcode) = $matches;
                if (isset($allow[$tcode])) {
                        $updateId[] = $tcode;
                        $msg[] = $tcode . ': 更新成功！';
                } else {
                    $msg[] = $tcode . ': 快递单不存在';
                }
            }

            if (!empty($updateId)) {
                \App\Model\Simple\Order::whereIn('tcode', $updateId)->update(['type_id' => 21]);
            }
        }
        //*****DY*****批量更新退货(通过物流单号)
        if ($code == "tuihuo") {
            if (Auth::user()->hasRole(['money.admin'])) {
                $tcodes = [];
                foreach ($row as $value) {
                    if (empty($value)) {
                        continue;
                    }

                    list($tcode, $sprice) = preg_split('/[\s,\t]+/', $value);
                    if (empty($tcode)) {
                        continue;
                    } else {
                        $tcodes[] = array('tcode' => $tcode, 'sprice' => $sprice);
                    }
                }

                $orders = \App\Model\Simple\Order::select(DB::raw('type_id,tcode,sprice'))->whereIn('tcode', array_pluck($tcodes, 'tcode'))->get();
                $allow = [];
                foreach ($orders as $order) {
                    $allow[$order->tcode] = $order->type_id;
                }

                foreach ($tcodes as $value) {
                    $tcode = trim($value['tcode'], ' ');
                    $sprice = $value['sprice'];

                    if (isset($allow[$tcode])) {
                        if ($allow[$tcode] == $types['qianshou']['id'] || $allow[$tcode] == $types['shoukuang']['id'] || $allow[$tcode] == $types['tuihuo']['id']) {
                            \App\Model\Simple\Order::where('tcode', $tcode)->update(['type_id' => $_type_id, 'sprice' => $sprice]);
                            $msg[] = $tcode . ': 更新成功!';
                        } else {
                            $msg[] = $tcode . ': 更新失败!!!!';
                        }
                    } else {
                        $msg[] = $tcode . ': 快递单号不存在';
                    }
                }
            } else {
                $orders = \App\Model\Simple\Order::select(DB::raw('id,tcode,type_id'))->whereIn('tcode', $row)->get();
                $allow = [];
                foreach ($orders as $order) {
                    $allow[$order->tcode] = $order->type_id;
                }

                $updateId = [];
                foreach ($row as $value) {
                    $tcode = trim($value, ' ');
                    if (isset($allow[$tcode])) {
                        if ($allow[$tcode] == $types['qianshou']['id'] || $allow[$tcode] == $types['shoukuang']['id']) {
                            $updateId[] = $tcode;
                            $msg[] = $tcode . ': 更新成功！';
                        } else {
                            $msg[] = $tcode . ': 更新失败!!!!';
                        }
                    } else {
                        $msg[] = $tcode . ': 快递单不存在';
                    }
                }

                if (!empty($updateId)) {
                    \App\Model\Simple\Order::whereIn('tcode', $updateId)->where('payMode',7)->update(['type_id' => $_type_id,'sprice'=>0]);
                    \App\Model\Simple\Order::whereIn('tcode', $updateId)->where('payMode','<>',7)->update(['type_id' => $_type_id]);
                }
            }
        }

        //*****DY*****批量更新收款
        if ($code == "shoukuang") {

            $tcodes = [];
            foreach ($row as $value) {
                if (empty($value)) {
                    continue;
                }

                list($tcode) = preg_split('/[\s,\t]+/', $value);
                if (empty($tcode)) {
                    continue;
                } else {
                    $tcodes[] = array('tcode' => $tcode);
                }
            }

            /*$orders = \App\Model\Simple\Order::select(DB::raw('type_id,tcode,sprice'))->whereIn('tcode',array_pluck($tcodes,'tcode'))->get();
            $allow = [];
            foreach ($orders as $order) {
            $allow[$order->tcode] = $order->type_id;
            }*/

            foreach ($tcodes as $value) {
                $tcode = trim($value['tcode'], ' ');
                //更新收款金额，状态变收款（订单初始状态不限制）
                if (isset($tcode)) {
                    \App\Model\Simple\Order::where('tcode', $tcode)->update(['type_id' => $_type_id]);
                    $msg[] = $tcode . ': 更新成功!';
                } else {
                    $msg[] = $tcode . ': 更新失败!!!!';
                }
            }
        }
        //*****DY*****批量更新重出单
        if ($code == "chongchu") {

            $orders = \App\Model\Simple\Order::select(DB::raw('id,type_id'))->whereIn('id', $row)->get();
            $allow = [];
            foreach ($orders as $order) {
                $allow[$order->id] = $order->type_id;
            }

            $updateId = [];
            foreach ($row as $value) {
                $id = trim($value);
                if (isset($allow[$id])) {
                    $updateId[] = $id;
                    $msg[] = $id . ': 更新成功！';
                } else {
                    $msg[] = $id . ': 更新失败!!!!';
                }
            }

            if (!empty($updateId)) {
                \App\Model\Simple\Order::whereIn('id', $updateId)->update(['type_id' => $_type_id]);
            }
        }
        //*****DY*****批量更新确认中
        if ($code == "queren") {

            $orders = \App\Model\Simple\Order::select(DB::raw('id,type_id'))->whereIn('id', $row)->get();
            $allow = [];
            foreach ($orders as $order) {
                $allow[$order->id] = $order->type_id;
            }

            $updateId = [];
            foreach ($row as $value) {
                $id = trim($value);
                if (isset($allow[$id])) {
                    if ($allow[$id] == $types['diaohuo']['id'] || $allow[$id] == $types['chongchu']['id'] || $allow[$id] == $types['xiadan']['id']) {
                        $updateId[] = $id;
                        $msg[] = $id . ': 更新成功！';
                    } else {
                        $msg[] = $id . ': 更新失败!!!!';
                    }
                } else {
                    $msg[] = $id . ': 订单号不存在!!!!';
                }
            }

            if (!empty($updateId)) {
                \App\Model\Simple\Order::whereIn('id', $updateId)->update(['type_id' => $_type_id]);
            }
        }

        return response()->json(['msg' => implode("\n", $msg)]);
    }
}