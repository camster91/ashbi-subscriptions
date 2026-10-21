<?php
/** Actual coupon helpers against offline WooCommerce/database doubles. */
// phpcs:ignoreFile -- Compact isolated runtime doubles; no provider or database access.
define('ABSPATH','/');
function get_post_meta($id,$key,$single=true){return '_subscrpt_price'===$key?$GLOBALS['coupon_price']:'';}
function apply_filters($hook,$value,...$args){if('subscrpt_coupon_is_recurring'===$hook){return $GLOBALS['coupon_recurring'];}if('subscrpt_coupon_recurring_limit'===$hook){return $GLOBALS['coupon_limit'];}return $value;}
function wc_get_order($id){return $GLOBALS['coupon_orders'][$id]??false;}
function wc_format_decimal($number){return number_format($number,2,'.','');}
class WC_Coupon {private $code;public function __construct($code){$this->code=$code;}public function get_code(){return $this->code;}}
class CouponLimitLine {public function get_code(){return 'fabricated-five';}public function get_discount(){return 5;}public function get_discount_tax(){return 0;}}
class WC_Order_Item_Product {public $total=20;public function get_id(){return 1;}public function get_subtotal(){return 20;}public function get_quantity(){return 1;}public function get_subtotal_tax(){return 0;}public function set_total($v){$this->total=$v;}public function set_total_tax($v){}public function save(){}}
class WC_Order {public $coupons=array();public $paid;public $item;public function __construct($paid=false){$this->paid=$paid;$this->item=new WC_Order_Item_Product();}public function is_paid(){return $this->paid;}public function get_items($type='line_item'){return array(new CouponLimitLine());}public function add_coupon($code,$amount,$tax){$this->coupons[$code]=(float)$amount;}public function get_item($id,$flag=false){return $this->item;}}
$GLOBALS['wpdb']=new class {public $prefix='wp_';public function prepare($query,...$args){return $query;}public function get_results($query){return $GLOBALS['coupon_relations'];}};
require dirname(__DIR__,2).'/plugin/includes/functions.php';
require dirname(__DIR__,2).'/plugin/includes/Illuminate/Helper.php';
function coupon_relation($order,$type='renew'){return (object)array('order_id'=>$order,'type'=>$type);}
$GLOBALS['coupon_orders']=array(10=>new WC_Order(true),11=>new WC_Order(false),12=>new WC_Order(false),13=>new WC_Order(true),20=>new WC_Order(true));
$GLOBALS['coupon_price']=20;$GLOBALS['coupon_limit']=2;$GLOBALS['coupon_recurring']=true;
$source=$GLOBALS['coupon_orders'][10];$item=$source->item;
$GLOBALS['coupon_relations']=array(coupon_relation(10,'new'),coupon_relation(10,'renew'),coupon_relation(11,'early-renew'),coupon_relation(12),coupon_relation(13,'switch'),coupon_relation(999));
$helper='SpringDevs\\Subscription\\Illuminate\\Helper';$results=array();
$results['unpaid_display']=$helper::get_subscription_recurring_discount(99,$source,$item);
$builder=new ReflectionMethod($helper,'add_recurring_coupons_to_renewal_order');$builder->setAccessible(true);$target=new WC_Order();$builder->invoke(null,$target,$source,$item,1,99);$results['unpaid_builder']=$target->coupons;
$GLOBALS['coupon_relations']=array(coupon_relation(10,'new'));
$results['price_before']=$helper::get_subscription_recurring_discount(100,$source,$item);
$GLOBALS['coupon_price']=40;$results['price_after']=$helper::get_subscription_recurring_discount(100,$source,$item);$GLOBALS['coupon_price']=20;
$results['payment_before']=$helper::get_subscription_recurring_discount(101,$source,$item);
$GLOBALS['coupon_relations'][]=coupon_relation(20);$results['payment_after']=$helper::get_subscription_recurring_discount(101,$source,$item);
$target=new WC_Order();$builder->invoke(null,$target,$source,$item,1,101);$results['exhausted_builder']=$target->coupons;
$GLOBALS['coupon_limit']=0;$results['unlimited']=$helper::get_subscription_recurring_discount(102,$source,$item);
$GLOBALS['coupon_limit']=1;$results['initial_only']=$helper::get_subscription_recurring_discount(103,$source,$item);
$GLOBALS['coupon_relations']=array();$results['initial_without_history']=$helper::get_subscription_recurring_discount(105,$source,$item);$target=new WC_Order();$builder->invoke(null,$target,$source,$item,1,105);$results['initial_builder']=$target->coupons;
$GLOBALS['coupon_recurring']=false;$results['one_time']=$helper::get_subscription_recurring_discount(104,$source,$item);
echo json_encode($results);
