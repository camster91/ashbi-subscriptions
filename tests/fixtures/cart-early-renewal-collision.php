<?php
/** Real manual-renewal helper with isolated pre-payment invoice records. */
// phpcs:ignoreFile -- Compact offline doubles; no WordPress/provider access.
namespace SpringDevs\Subscription\Illuminate {
    class CancellationEvidence {public static function lock($id){return true;}public static function blocked($id){return false;}public static function unlock($id){}}
    class RenewalClaim {public static function acquire($id,$order=0,$anchor=null){$GLOBALS['cart_claim']=array('order_id'=>0,'period_anchor'=>$anchor);return array('acquired'=>true,'period_key'=>'fabricated-period','token'=>'fabricated-token');}public static function finalize($id,$key,$token,$order){$GLOBALS['cart_claim']['order_id']=$order;return true;}public static function release($id,$key,$token){$GLOBALS['cart_claim']=null;}}
}
namespace {
    function get_post_status($id){return 'expired';}
    function get_post_meta($id,$key,$single=true){return '_subscrpt_next_date'===$key?$GLOBALS['cart_anchor']:'';}
    function wc_get_order($id){return $GLOBALS['cart_orders'][$id]??false;}
    function subscrpt_renewal_is_migration_blocked($id){return false;}
    function subscrpt_write_log($message){}
    function wp_insert_comment($data){return 1;}
    function update_comment_meta($id,$key,$value){}
    function do_action($hook,...$args){}
    function __($text,$domain=''){return $text;}
    class WC_Order {public $id;public $status;public function __construct($id,$status='pending'){$this->id=$id;$this->status=$status;}public function get_id(){return $this->id;}public function get_status(){return $this->status;}public function get_meta($key){return '_subscrpt_early_renewal_anchor'===$key?$GLOBALS['cart_anchor']:'';}public function update_meta_data($key,$value){}public function save(){return $this->id;}}
    $GLOBALS['wpdb']=new class {public $prefix='wp_';public function prepare($sql,...$args){return $sql;}public function get_results($sql){return $GLOBALS['cart_relations'];}public function get_var($sql){return null;}public function insert($table,$data,$formats=null){$GLOBALS['cart_relations'][]=(object)$data;return 1;}};
    require dirname(__DIR__,2).'/plugin/includes/Illuminate/Helper.php';
    $GLOBALS['cart_anchor']=time()-60;$results=array();
    foreach(array('pending','failed','on-hold','completed','cancelled','refunded') as $state){
        $GLOBALS['cart_claim']=null;$GLOBALS['cart_orders']=array(10=>new WC_Order(10,$state),20=>new WC_Order(20));
        $GLOBALS['cart_relations']=array((object)array('subscription_id'=>99,'order_id'=>10,'order_item_id'=>100,'type'=>'early-renew'));
        $result=\SpringDevs\Subscription\Illuminate\Helper::process_order_renewal(99,20,200);
        $results[$state]=array('accepted'=>$result,'claim'=>$GLOBALS['cart_claim'],'relations'=>count($GLOBALS['cart_relations']));
    }
    echo json_encode($results);
}
