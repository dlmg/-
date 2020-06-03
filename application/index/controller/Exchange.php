<?php
/**
 * Created by PhpStorm.
 * User: wcg
 * Create_time: 2020/5/28 17:54
 */

namespace app\index\controller;

use think\Db;

/**
 * Class Exchange 交易所类
 * @package app\index\controller
 * @create_time 2020/5/28 18:12:12
 * @Author MG <dlmg521@163.com>
 */
class Exchange extends Basis
{

    /**
     * 我要卖/买数据
     * @param int $tag
     * @throws \think\exception\DbException
     * @create_time: 2020/5/29 10:01:07
     * @author: wcg
     */
    public function transaction($tag = 1, $coin_name = 'AGB')
    {
        if ($tag == 1) {
            $map['tag'] = 2;
        } elseif ($tag == 2) {
            $map['tag'] = 1;
        } else {
            $this->e_msg('参数错误');
        }
        $result = Db::name('publish')
            ->where($map)
            ->where('coin_name', $coin_name)
            ->paginate(10);
        if ($result->isEmpty()) {
            $this->e_msg('暂无数据');
        }
        $this->s_msg('null', $result);
    }

    /**
     * 交易所挂单
     * @create_time: 2020/5/29 9:44:19
     * @author: wcg
     */
    public function publish()
    {
        $user_id = $this->user['id'];
        if ($this->user['us_type'] !== 3) {
            $this->e_msg('你不是系统服务商');
        }
        if (request()->isGet()) {
            $coin_id = input('coin_id') ?: '1';
            $data['count'] = Db::name('ex_order')
                ->where("(from_id = $user_id or to_id = $user_id) and status = 3")
                ->count();//总成交单数
            $data['available'] = Db::name('money')
                ->where('user_id', $user_id)
                ->where('coin_id', $coin_id)
                ->value('money') ?: 0;
            $data['name'] = $this->user['us_nickname'];
            $this->s_msg('null', $data);
        } elseif (request()->isPost()) {

            $data = input('post.');
            $data['user_id'] = $user_id;
            $data['payment'] = implode(',', $data['payment']);
            $data['add_time'] = date('Y-m-d H:i:s');

            if ($data['coin_name'] != 'UZF' && $data['coin_name'] != 'AGB') {
                $this->e_msg('参数传递错误');
            }

            if ($data['tag'] == 1) {
                $available = Db::name('money')
                    ->where('user_id', $user_id)
                    ->where('coin_name', $data['coin_name'])
                    ->value('money');
                if ($available < $data['number']) {
                    $this->e_msg('超过了可用余额');
                }
                $number = $data['number'];
                Db::startTrans();
                try {
                    $result = Db::name('publish')->insertGetId($data);
                    $info = Db::name('money')
                        ->where('user_id', $user_id)
                        ->where('coin_name', $data['coin_name'])
                        ->update([
                            'money' => Db::raw("money-($number+$number*0.005)"),
                            'lock_money' => Db::raw("lock_money+($number)")
                        ]);
                } catch (\Exception $e) {
                    Db::rollback();
                    return $e->getMessage();
                }
                if ($result && $info) {
                    Db::commit();
                    $this->s_msg('挂单成功');
                } else {
                    $this->e_msg('失败');
                }
            } elseif ($data['tag'] == 2) {
                $result = Db::name('publish')->insertGetId($data);
                if ($result) {
                    $this->s_msg('挂单成功', $result);
                }
            }
        }
    }

    /**
     * 发布记录
     * @throws \think\exception\DbException
     * @create_time: 2020/5/29 11:57:45
     * @author: wcg
     */
    public function publishList()
    {
        $user_id = $this->user['id'];
        $result = Db::name('Publish')
            ->where('user_id', $user_id)
            ->paginate(10)
            ->each(function ($item, $key) {
                if ($item['tag'] == 1) {
                    $item['tag'] = '出售';
                } elseif ($item['tag'] == 2) {
                    $item['tag'] = '求购';
                }
                return $item;
            });
        if ($result->isEmpty()) {
            $this->s_msg('暂时没有发布记录');
        }
        $this->s_msg('查询成功', $result);
    }

    /**
     * 挂单详情
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     * @create_time: 2020/5/29 14:20:08
     * @author: wcg
     */
    public function detail()
    {
        $id = input('id');
        $data = Db::name('Publish')->where('id', $id)->find();
        $data['volume'] = sprintf("%.8f", Db::name('ex_order')->where('pu_id', $id)->sum('amount'));
        $this->s_msg('null', $data);
    }

    /**
     * 我要买下单处理逻辑
     * @return string
     * @create_time: 2020/5/30 14:32:09
     * @author: wcg
     */
    public function buy()
    {
        $data['pu_id'] = input('id');
        $data['from_id'] = input('user_id');
        $data['to_id'] = $this->user['id'];
        $data['number'] = input('buy_num');
        $data['amount'] = input('buy_price');
        $data['add_time'] = date('Y-m-d H:i:s');
        $data['coin_name'] = input('coin_name');
        $data['or_num'] = date('YmdHis') . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
        $data['reference'] = str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
        Db::startTrans();
        try {
            $info = Db::name('publish')
                ->where('id', $data['pu_id'])
                ->setDec('number', $data['number']);

            $result = Db::name('ex_order')
                ->field('status', true)
                ->insertGetId($data);
        } catch (\Exception $e) {
            Db::rollback();
            return $e->getMessage();
        }

        if ($result && $info) {
            Db::commit();
            $this->s_msg('下单成功', $result);
        } else {
            $this->e_msg('下单失败');
        }
    }

    /**
     * 我要卖下单处理逻辑
     * @return string
     * @create_time: 2020/5/30 15:52:07
     * @author: wcg
     */
    public function sell()
    {
        $data['pu_id'] = input('id');
        $data['from_id'] = $this->user['id'];
        $data['to_id'] = input('user_id');
        $data['number'] = input('buy_num');
        $data['amount'] = input('buy_price');
        $data['add_time'] = date('Y-m-d H:i:s');
        $data['coin_name'] = input('coin_name');
        $data['or_num'] = date('YmdHis') . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
        $data['reference'] = str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
        $coin_name = input('coin_name');

        $number = $data['number'];
        $money = Db::name('money')
            ->where('user_id', $this->user['id'])
            ->where('coin_name', $coin_name)
            ->value('money');
        if ($money < $data['number']) {
            $this->e_msg('你的' . $coin_name . '数量不足');
        }
        Db::startTrans();
        try {
            $info = Db::name('publish')
                ->where('id', $data['pu_id'])
                ->setDec('number', $data['number']);

            Db::name('money')
                ->where('user_id', $data['from_id'])
                ->where('coin_name', $coin_name)
                ->update([
                    'money' => Db::raw("money-$number"),
                    'lock_money' => Db::raw("lock_money+$number")
                ]);

            $result = Db::name('ex_order')
                ->field('status', true)
                ->insertGetId($data);

        } catch (\Exception $e) {
            Db::rollback();
            return $e->getMessage();
        }

        if ($result && $info) {
            Db::commit();
            $this->s_msg('下单成功', $result);
        } else {
            $this->e_msg('下单失败');
        }
    }

    /**
     * 取消我要买的订单
     * @throws \think\Exception
     * @create_time: 2020/5/30 16:09:03
     * @author: wcg
     */
    public function cancleBuy()
    {
        $id = input('id');
        $number = input('number');
        $pu_id = Db::name('ex_order')->where('id', $id)->value('pu_id');
        Db::name('ex_order')->where('id', $id)->setField('status', 2);            //修改订单状态为已取消
        Db::name('publish')->where('id', $pu_id)->setInc('number', $number);      //把所属挂单的数量恢复
        $this->s_msg('取消成功');
    }

    /**
     * 交易所订单
     * @throws \think\exception\DbException
     * @create_time: 2020/5/30 17:22:16
     * @author: wcg
     */
    public function orderList()
    {
        $user_id = $this->user['id'];
        if (input('get.type')) {
            $type = input('get.type');
            if($type == 'sell'){
                $map['from_id'] = $user_id;
            }elseif($type == 'buy'){
                $map['to_id'] = $user_id;
            }
        }
        if (input('get.status')) {
            $status = input('get.status');
            $map['status'] = $status;
        }
        $map['from_id|to_id'] = ['=',$user_id];
        $data = Db::name('ex_order')
            ->where($map)
            ->paginate(10)
            ->each(function ($item) {
                if ($item['from_id'] != $this->user['id']) {
                    $item['seller'] = Db::name('user')->where('id', $item['from_id'])->value('us_nickname');
                } else {
                    $item['buyer'] = Db::name('user')->where('id', $item['to_id'])->value('us_nickname');
                }
                return $item;
            });
        unset($item);
        if ($data->isEmpty()) {
            $this->e_msg('暂无订单');
        } else {
            $this->s_msg('成功', $data);
        }
    }

    /**
     * 已付款，下一步
     * @create_time: 2020/5/30 17:59:05
     * @author: wcg
     */
    public function paid()
    {
        $id = input('id');
        Db::name('ex_order')->where('id', $id)->setField('status', 3);
        $this->s_msg('操作成功');
    }

    /**
     * 我已确认收款
     * @create_time: 2020/6/1 9:40:02
     * @author: wcg
     */
    public function arrive()
    {
        $id = input('id');
        Db::name('ex_order')->where('id', $id)->setField('status', 4);
        $this->s_msg('操作成功');
    }

}