<?php
/** Real Stripe dispatch and read-only cancelled-dispatch reconciliation, offline. */
// phpcs:ignoreFile -- Compact isolated gateway, provider and database doubles.
define('ABSPATH','/');
class WP_Error {public $code;public function __construct($code='',$text=''){$this->code=$code;}}
class WC_Stripe_Exception extends Exception {}
class WC_Stripe_Payment_Gateway {
 public function prepare_order_source($order){++$GLOBALS['sr_prepare'];return (object)array('customer'=>'cus_offline','source'=>'pm_offline');}
 public function create_and_confirm_intent_for_off_session($order,$source,$amount){++$GLOBALS['sr_dispatch'];$GLOBALS['sr_barrier']=true;return stripe_race_intent();}
}
class WC_Stripe_Order_Helper {
 public function validate_minimum_order_amount($order){++$GLOBALS['sr_validate'];}
 public function lock_order_payment($order){++$GLOBALS['sr_order_locks'];return false;}
 public function unlock_order_payment($order){++$GLOBALS['sr_order_unlocks'];}
}
class WC_Stripe_Logger {public static function info($message){}public static function error($message){}}
class WC_Stripe_Helper {public static function get_stripe_amount($amount,$currency){return (int)round($amount*100);}}
class WC_Stripe_API {
 public static function retrieve($endpoint){$GLOBALS['sr_reads'][]=$endpoint;if('before_dispatch'===$GLOBALS['sr_case']){$GLOBALS['sr_barrier']=true;return (object)array('data'=>array(),'has_more'=>false);}if('during_dispatch'===$GLOBALS['sr_case']){return (object)array('data'=>array(),'has_more'=>false);}if('reconcile_error'===$GLOBALS['sr_case']){return new WP_Error('timeout');}return stripe_race_intent();}
 public static function request_with_level3_data(...$args){++$GLOBALS['sr_dispatch'];throw new RuntimeException('unexpected provider mutation');}
}
function stripe_race_intent(){return (object)array('id'=>'pi_offline','status'=>'succeeded','customer'=>'cus_offline','currency'=>'usd','amount'=>1000,'metadata'=>(object)array('ashbi_renewal_identity'=>'offline-identity'));}
class StripeRaceOrder {
 public $meta=array('_subscrpt_renewal_period_key'=>'period-99','_subscrpt_stripe_renewal_customer'=>'cus_offline','_subscrpt_stripe_renewal_identity'=>'offline-identity');
 public function get_id(){return 501;}public function get_meta($key,$single=true){return $this->meta[$key]??'';}public function get_total(){return 10;}public function get_currency(){return 'USD';}public function get_order_number(){return '501';}
 public function update_meta_data($key,$value){$this->meta[$key]=$value;}public function save(){++$GLOBALS['sr_order_saves'];return 501;}
}
function wc_get_order($id){return $GLOBALS['sr_order'];}
function is_wp_error($value){return $value instanceof WP_Error;}
function subscrpt_subscription_auto_renew_enabled($id){return true;}
function subscrpt_is_auto_renew_enabled(){return true;}
function get_option($key,$default=false){return 'wp_subscription_stripe_auto_renew'===$key?'1':$default;}
function sanitize_text_field($value){return (string)$value;}function sanitize_key($value){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$value));}
function wp_json_encode($value){return json_encode($value);}function current_time($type,$gmt=false){return '2026-10-10 12:00:00';}
function subscrpt_write_log($message){}function subscrpt_write_debug_log($message){}function __($text,$domain=''){return $text;}
function untrailingslashit($value){return rtrim($value,'/');}function get_site_url(){return 'https://site.example.test';}
class StripeRaceDatabase {
 public $prefix='wp_';public $last_error='';public $prepared=array();public $events=array();
 public function prepare($sql,...$args){if(count($args)===1&&is_array($args[0])){$args=$args[0];}$key='sql-'.count($this->prepared);$this->prepared[$key]=array($sql,$args);return $key;}
 public function get_var($key){list($sql,$args)=$this->prepared[$key];$this->last_error='';if(strpos($sql,'GET_LOCK')!==false){++$GLOBALS['sr_locks'];return 1;}if(strpos($sql,'RELEASE_LOCK')!==false){++$GLOBALS['sr_unlocks'];return 1;}if(strpos($sql,'SELECT subscription_id FROM')===0){return $GLOBALS['sr_barrier']?99:null;}if(strpos($sql,'COUNT(*)')!==false){return 1;}return 0;}
 public function query($key){return 1;}public function insert($table,$data,$format=null){$this->events[]=$data;return 1;}
}
require dirname(__DIR__,2).'/plugin/includes/Illuminate/CancellationEvidence.php';
require dirname(__DIR__,2).'/plugin/includes/Illuminate/RenewalClaim.php';
require dirname(__DIR__,2).'/plugin/includes/Illuminate/Gateways/Stripe/Stripe.php';
$class='SpringDevs\\Subscription\\Illuminate\\Gateways\\Stripe\\Stripe';$gateway=(new ReflectionClass($class))->newInstanceWithoutConstructor();$results=array();
foreach(array('already_cancelled','before_dispatch','during_dispatch','reconcile','reconcile_error') as $case){
 $GLOBALS['sr_case']=$case;$GLOBALS['sr_barrier']=in_array($case,array('already_cancelled','reconcile','reconcile_error'),true);$GLOBALS['sr_order']=new StripeRaceOrder();$GLOBALS['wpdb']=new StripeRaceDatabase();$GLOBALS['sr_reads']=array();
 foreach(array('sr_prepare','sr_validate','sr_dispatch','sr_locks','sr_unlocks','sr_order_locks','sr_order_unlocks','sr_order_saves') as $key){$GLOBALS[$key]=0;}
 if(in_array($case,array('during_dispatch','reconcile','reconcile_error'),true)){$GLOBALS['sr_order']->meta['_stripe_intent_id']='pi_offline';}
 // During-dispatch case must reach create; the provider lookup returns no prior object.
 if('during_dispatch'===$case){unset($GLOBALS['sr_order']->meta['_stripe_intent_id']);}
 $error=null;$response=null;
 try{if(in_array($case,array('reconcile','reconcile_error'),true)){$gateway->retry_renewal_payment(99,501);}else{$response=$gateway->pay_renew_order($GLOBALS['sr_order'],99);}}catch(Throwable $exception){$error=get_class($exception).': '.$exception->getMessage();}
 $results[$case]=array('error'=>$error,'response_code'=>$response instanceof WP_Error?$response->code:null,'dispatches'=>$GLOBALS['sr_dispatch'],'reads'=>$GLOBALS['sr_reads'],'prepare'=>$GLOBALS['sr_prepare'],'locks'=>$GLOBALS['sr_locks'],'unlocks'=>$GLOBALS['sr_unlocks'],'order_locks'=>$GLOBALS['sr_order_locks'],'order_unlocks'=>$GLOBALS['sr_order_unlocks'],'events'=>$GLOBALS['wpdb']->events);
}
echo json_encode($results);
