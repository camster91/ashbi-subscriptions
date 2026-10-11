<?php
/** Actual legacy finalization and cancellation feedback persistence, offline. */
// phpcs:ignoreFile -- Compact isolated WordPress database and mailer doubles.
define('ABSPATH','/');define('DAY_IN_SECONDS',86400);
class WP_Error {}
function is_wp_error($value){return $value instanceof WP_Error;}
function get_post($id){return (object)array('ID'=>$id,'post_type'=>'subscrpt_order','post_author'=>7);}
function get_post_status($id){return $GLOBALS['cf_status'];}
function get_post_meta($id,$key,$single=true){return $GLOBALS['cf_meta'][$key]??'';}
function update_post_meta($id,$key,$value){$GLOBALS['cf_meta'][$key]=$value;return true;}
function delete_post_meta($id,$key){unset($GLOBALS['cf_meta'][$key]);return true;}
function wp_update_post($data,$error=false){if($GLOBALS['cf_status_fail']){return $error?new WP_Error():0;}$GLOBALS['cf_status']=$data['post_status'];return $data['ID'];}
function get_posts($args){return 'pe_cancelled'===$GLOBALS['cf_status']?array(99):array();}
function WC(){return new class {public function mailer(){return !$GLOBALS['cf_mail_absent'];}};}
function wp_insert_comment($data){return 1;}function update_comment_meta($id,$key,$value){return true;}
function do_action($hook,...$args){}function apply_filters($hook,$value,...$args){return $value;}
function current_time($type,$gmt=false){return '2026-10-10 12:00:00';}function wp_json_encode($value){return json_encode($value);}
function sanitize_key($value){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$value));}function sanitize_text_field($value){return trim(strip_tags((string)$value));}function sanitize_textarea_field($value){return trim(strip_tags((string)$value));}
function get_option($key,$default=false){return 'subscrpt_cancellation_reasons'===$key?array(array('key'=>'reason1','label'=>'First reason'),array('key'=>'reason2','label'=>'Second reason')):$default;}
function __($text,$domain=''){return $text;}function absint($value){return abs((int)$value);}function wp_unslash($value){return $value;}
function check_ajax_referer($action,$field){return true;}function current_user_can($cap){return false;}function get_current_user_id(){return 7;}function is_user_logged_in(){return true;}
function wp_send_json_success($data){throw new RuntimeException('json_success');}function wp_send_json_error($data){throw new RuntimeException('json_error');}
function subscrpt_is_max_payments_reached($id){return false;}function subscrpt_write_log($text){}
class CancellationFinalizerHelper {public static function get_parent_order($id){return new class {public function get_payment_method(){return 'stripe';}};}}
class_alias('CancellationFinalizerHelper','SpringDevs\\Subscription\\Illuminate\\Helper');
class FinalizerEvidenceDatabase {
 public $prefix='wp_';public $last_error='';public $insert_id=0;public $prepared=array();public $barrier=null;public $feedback=array();public $mutations=array();
 public function prepare($sql,...$args){if(count($args)===1&&is_array($args[0])){$args=$args[0];}$key='sql-'.count($this->prepared);$this->prepared[$key]=array($sql,$args);return $key;}
 public function get_var($key){list($sql,$args)=$this->prepared[$key];$this->last_error='';if(strpos($sql,'GET_LOCK')!==false||strpos($sql,'RELEASE_LOCK')!==false){return 1;}if(strpos($sql,'SELECT subscription_id')===0){return $this->barrier?99:null;}if(strpos($sql,'cancellation_feedback')!==false){return $this->feedback?max(array_keys($this->feedback)):null;}return null;}
 public function get_row($key,$mode=null){return $this->barrier?(object)$this->barrier:null;}
 public function insert($table,$data,$formats=null){if(strpos($table,'cancellation_feedback')!==false){$data['id']=++$this->insert_id;$this->feedback[$data['id']]=$data;}return 1;}
 public function query($key){list($sql,$args)=$this->prepared[$key];$this->mutations[]=$sql;if(strpos($sql,'DELETE')===0){foreach($this->feedback as $id=>$row){if($id!==(int)$args[1]){unset($this->feedback[$id]);}}}return 1;}
 public function update($table,$data,$where,...$args){$this->mutations[]='UPDATE '.$table;$this->feedback[$where['id']]=array_merge($data,$where);return 1;}
}
function reset_finalizer_fixture(){$GLOBALS['wpdb']=new FinalizerEvidenceDatabase();$GLOBALS['cf_status']='pe_cancelled';$GLOBALS['cf_meta']=array('_subscrpt_cancel_at'=>time()-60,'_subscrpt_next_date'=>time()-60,'_subscrpt_auto_renew'=>1);$GLOBALS['cf_mail_absent']=false;$GLOBALS['cf_status_fail']=false;}
require dirname(__DIR__,2).'/plugin/includes/Illuminate/Action.php';require dirname(__DIR__,2).'/plugin/includes/Illuminate/CancellationEvidence.php';require dirname(__DIR__,2).'/plugin/includes/Illuminate/Cancellation.php';
$service=(new ReflectionClass('SpringDevs\\Subscription\\Illuminate\\Cancellation'))->newInstanceWithoutConstructor();$results=array();
reset_finalizer_fixture();$GLOBALS['cf_mail_absent']=true;$error=null;try{$service->process_due_cancellations();}catch(Throwable $exception){$error=$exception->getMessage();}$results['mailer_absent']=array('status'=>$GLOBALS['cf_status'],'error'=>$error);
reset_finalizer_fixture();$before=$GLOBALS['cf_meta'];$GLOBALS['cf_status_fail']=true;$service->cancel(99);$results['status_failure']=array('status'=>$GLOBALS['cf_status'],'before'=>$before,'after'=>$GLOBALS['cf_meta']);
reset_finalizer_fixture();$GLOBALS['wpdb']->barrier=array('subscription_id'=>99,'actor_id'=>7,'request_id'=>str_repeat('a',64),'requested_at'=>'2026-10-09 12:00:00','access_end'=>$GLOBALS['cf_meta']['_subscrpt_cancel_at']);$original=$GLOBALS['wpdb']->barrier;$service->cancel(99);$results['durable']=array('status'=>$GLOBALS['cf_status'],'meta'=>$GLOBALS['cf_meta'],'original'=>$original,'barrier'=>$GLOBALS['wpdb']->barrier);
reset_finalizer_fixture();$GLOBALS['cf_status']='active';$responses=array();foreach(array('reason1','reason2') as $index=>$reason){$_POST=array('subscription_id'=>99,'reason_key'=>$reason,'comment'=>'Feedback '.($index+1));try{$service->record_feedback();}catch(RuntimeException $exception){$responses[]=$exception->getMessage();}}$results['feedback']=array('rows'=>array_values($GLOBALS['wpdb']->feedback),'mutations'=>$GLOBALS['wpdb']->mutations,'responses'=>$responses,'status'=>$GLOBALS['cf_status']);
echo json_encode($results);
