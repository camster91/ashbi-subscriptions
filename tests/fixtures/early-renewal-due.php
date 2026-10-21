<?php
/** Actual period selection against isolated historical-order doubles. */
// phpcs:ignoreFile -- Compact offline doubles, no database or provider access.
function wc_get_order($id){return $GLOBALS['early_orders'][$id]??false;}
class WC_Order {private $anchor;private $status;public function __construct($anchor,$status){$this->anchor=$anchor;$this->status=$status;}public function get_meta($key){return $this->anchor;}public function get_status(){return $this->status;}public function get_id(){return array_search($this,$GLOBALS['early_orders'],true);}}
$GLOBALS['wpdb']=new class {public $prefix='wp_';public function prepare($sql,...$args){return $sql;}public function get_results($sql){return $GLOBALS['early_relations'];}};
require dirname(__DIR__,2).'/plugin/includes/Illuminate/Helper.php';
$GLOBALS['early_orders']=array(10=>new WC_Order(100,'pending'),11=>new WC_Order(100,'failed'),12=>new WC_Order(101,'pending'),13=>new WC_Order(100,'cancelled'),14=>new WC_Order(100,'refunded'),15=>new WC_Order(100,'on-hold'),16=>new WC_Order(100,'completed'));
$GLOBALS['early_relations']=array();
foreach(array(10,10,11,12,13,14,15,16,999) as $id){$GLOBALS['early_relations'][]=(object)array('order_id'=>$id,'type'=>'early-renew');}
$GLOBALS['early_relations'][]=(object)array('order_id'=>12,'type'=>'renew');
$method=new ReflectionMethod('SpringDevs\\Subscription\\Illuminate\\Helper','early_renewal_orders_for_period');$method->setAccessible(true);
echo json_encode(array_keys($method->invoke(null,99,100)));
