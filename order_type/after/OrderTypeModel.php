<?php

namespace App\Model\Simple;

use App\Model\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Auth;
use Cache;


class OrderType extends BaseModel
{
    protected $table = 'order_type';

    //订单状态
    const ORDER_TYPE_ORDERED = 0;//已下单
    const ORDER_TYPE_INSERT_STOREHOUSE= 1;//已入库
    const ORDER_TYPE_CONFIRMING = 2;//确认中
    const ORDER_TYPE_SENDED = 3;//已发货
    const ORDER_TYPE_NO_TREATMENT = 4;//不处理
    const ORDER_TYPE_SIGNED = 5;//已签收
    const ORDER_TYPE_DENIED = 6;//拒签
    const ORDER_TYPE_RETURNED = 7;//退货
    const ORDER_TYPE_CANCELLED = 8;//取消单
    const ORDER_TYPE_RECEIVABLES = 9;//已收款
    const ORDER_TYPE_STOREHOUSE_NOENOUGH= 10;//已缺货
    const ORDER_TYPE_REORDER = 11;//重出单
    const ORDER_TYPE_VALID_ORDER = 12;//有效单
    const ORDER_TYPE_PURCHASE_ORDER = 13;//采购单
    const ORDER_TYPE_PURCHASED = 14;//已采购
    const ORDER_TYPE_REAPPEARED = 16;//已重出
    const ORDER_TYPE_INTERCEPT = 17;//拦截单
    const ORDER_TYPE_LEAVE_STOREHOUSE = 19;//已出仓
    const ORDER_TYPE_DANGER = 20;//风险单

    protected static function boot(){
        parent::boot();

        static::addGlobalScope('sort', function(Builder $builder) {
            $builder->orderBy('sorted');
        });
    }

    /**
     * 订单状态权限映射表
     * @var array
     */
    public static $typeMap = [
        "xiadan" => [],
        "diaohuo" => ['xiadan','youxiao'],
        "queren" => ['diaohuo','chongchu','xiadan'],
        "fahuo" => ['chucang','chongchu'],
        "buchuli" => [],
        "qianshou" => ['fahuo','wentijian'],
        "juqian" => ['fahuo','qianshou','wentijian'],
        "tuihuo" => ['qianshou','shoukuang','tuihuo'],
        "quxiao" => [],
        "shoukuang" => [],
        "quehuo" => [],
        "chongchu" => [],
        "youxiao" => ['xiadan'],
        "caigou" => [],
        "yicaigou" => [],
        "yichongchu" => [],
        "lanjie" => [],
        "chucang" => ['diaohuo','yicaigou','youxiao','caigou'],
        "fengxian" => [],
        "tuijian" => [],
        "wentijian" => ['fahuo'],
    ];

    /**
     * 检验订单状态是否可改
     * @param $check_type 当前要修改的状态
     * @param $check_content_type 允许被改的状态
     * @return bool
     */
    public static function checkTypeAuth($check_type,$check_content_type){
        if(empty(self::$typeMap[$check_type])||in_array($check_content_type,self::$typeMap[$check_type])){
            return true;
        }
        return false;
    }

    /**
     * 从缓存中获取数据
     * @return mixed
     */
    public static function getFromCached()
    {
        return Cache::remember('order_type_all', 5*60, function(){
            return self::all();
        });
    }

}
