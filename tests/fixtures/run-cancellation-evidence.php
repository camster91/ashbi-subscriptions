<?php
/** Offline real-source cancellation evidence regressions. */
// phpcs:ignoreFile -- Isolated test doubles intentionally implement a compact WordPress runtime.
define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );
class WP_Error {}
class CancellationEvidenceHelperFixture { public static function get_parent_order( $id ) { return $GLOBALS['ce_parent_order']; } }
class_alias( 'CancellationEvidenceHelperFixture', 'SpringDevs\\Subscription\\Illuminate\\Helper' );
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_current_blog_id() { return 1; }
function get_current_user_id() { return $GLOBALS['ce_user']; }
function is_user_logged_in() { return $GLOBALS['ce_user'] > 0; }
function current_user_can( $cap ) { return $GLOBALS['ce_admin'] && in_array( $cap, array( 'manage_options', 'manage_woocommerce' ), true ); }
function get_post( $id ) { return 99 === (int) $id ? (object) array( 'ID'=>99, 'post_author'=>7, 'post_type'=>'subscrpt_order' ) : null; }
function get_post_status( $id ) { return $GLOBALS['ce_status']; }
function get_post_meta( $id, $key, $single = true ) { return $GLOBALS['ce_meta'][$key] ?? ''; }
function metadata_exists( $type, $id, $key ) { return array_key_exists( $key, $GLOBALS['ce_meta'] ); }
function update_post_meta( $id, $key, $value ) { if ( $GLOBALS['ce_meta_fail'] ) { return false; } $GLOBALS['ce_meta'][$key] = $value; return true; }
function wp_update_post( $data, $error = false ) { $GLOBALS['ce_status_writes'][]=$data; if ( $GLOBALS['ce_status_fail'] ) { return $error ? new WP_Error() : 0; } $GLOBALS['ce_status'] = $data['post_status']; return $data['ID']; }
function current_time( $type, $gmt = false ) { return 'mysql' === $type ? '2026-10-10 12:00:00' : 1791633600; }
function wp_generate_uuid4() { return '00000000-0000-4000-8000-000000000001'; }
function sanitize_key( $text ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $text ) ); }
function sanitize_text_field( $text ) { return trim( strip_tags( (string) $text ) ); }
function absint( $value ) { return abs( (int) $value ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function __( $text, $domain = '' ) { return $text; }
function apply_filters( $hook, $value, ...$args ) { return $value; }
function subscrpt_is_max_payments_reached( $id ) { return false; }
function wp_insert_comment( $data ) { $GLOBALS['ce_comments'][]=$data; return $GLOBALS['ce_comment_fail'] ? false : 1; }
function update_comment_meta( $id, $key, $value ) { return true; }
function subscrpt_write_log( $message ) {}
function WC() { return new class { public function mailer() { if ( $GLOBALS['ce_mail_fail'] ) { throw new RuntimeException( 'fixture mail failure' ); } return true; } }; }
function wc_get_order( $id ) { return new class { public function get_payment_method() { return 'stripe'; } public function get_meta( $key, $single = true ) { return ''; } }; }
function do_action( $hook, ...$args ) { $GLOBALS['ce_hooks'][] = $hook; foreach($GLOBALS['ce_action_callbacks'][$hook]??array() as $callback){$callback(...$args);} }
function add_action( $hook, $callback, ...$args ) { $GLOBALS['ce_action_callbacks'][$hook][]=$callback; }
function as_schedule_single_action( $time, $hook, $args, $group='', $unique=false ) { $GLOBALS['ce_queued'][]=array('hook'=>$hook,'args'=>$args);return $GLOBALS['ce_queue_fail']?0:count($GLOBALS['ce_queued']); }

/** Database double models immutable primary key and append-only ledger. */
class CancellationEvidenceDatabase {
 public $prefix = 'wp_';
 public $posts = 'wp_posts';
 public $postmeta = 'wp_postmeta';
 public $last_error = '';
 public $insert_id = 0;
 public $barriers = array();
 public $events = array();
 public $queries = array();
 public $prepared = array();
 public $fail_read = false;
 public $fail_barrier = false;
 public $fail_audit = false;
 public $fail_audit_at = 0;
 public $audit_attempts = 0;
 public $fail_lock = false;
 public $mutations = array();
 public function prepare( $sql, ...$args ) { if ( 1 === count($args) && is_array($args[0]) ) { $args=$args[0]; } $key='fixture-sql-'.count($this->prepared); $this->prepared[$key]=array($sql,$args); return $key; }
 public function unpack( $sql ) { return $this->prepared[$sql] ?? array($sql,array()); }
 public function get_var( $prepared ) {
  list($sql,$args)=$this->unpack($prepared); $this->queries[]=$sql; $this->last_error='';
  if ( false !== strpos($sql,'GET_LOCK') ) { return $this->fail_lock ? 0 : 1; }
  if ( false !== strpos($sql,'RELEASE_LOCK') ) { return 1; }
  if ($this->fail_read) { $this->last_error='fixture read unavailable'; return null; }
  $id=(int)end($args); return isset($this->barriers[$id]) ? $id : null;
 }
 public function get_row( $prepared, $output = null ) { list($sql,$args)=$this->unpack($prepared); $this->last_error=$this->fail_read?'fixture read unavailable':''; $row=$this->barriers[(int)end($args)]??null; return $this->fail_read?null:('ARRAY_A'===$output?$row:($row?(object)$row:null)); }
 public function get_results( $prepared, $output = null ) { list($sql,$args)=$this->unpack($prepared);$this->last_error=$this->fail_read?'fixture read unavailable':'';if($this->fail_read){return array();}if(strpos($sql,'SELECT b.subscription_id')===0){$rows=array();foreach($this->barriers as $id=>$barrier){if(($GLOBALS['ce_meta']['_ashbi_cancellation_confirmed']??0)!=1 || ((int)$barrier['access_end']<=time() && 'cancelled'!==$GLOBALS['ce_status'])){$rows[]=(object)array('subscription_id'=>$id);}}return $rows;}return array(); }
 public function insert( $table, $data, $formats = null ) { $this->last_error=''; $this->mutations[]=array('insert',$table,$data); if(false!==strpos($table,'evidence_event')) { ++$this->audit_attempts; if($this->fail_audit || $this->fail_audit_at === $this->audit_attempts){$this->last_error='fixture audit unavailable';return false;} $this->events[]=$data; $this->insert_id=count($this->events);return 1; } return false; }
 public function query( $prepared ) {
  list($sql,$args)=$this->unpack($prepared); $this->queries[]=$sql; $this->last_error='';
  if(false!==strpos($sql,'INSERT IGNORE') && false!==strpos((string)($args[0]??''),'cancellation_barrier')){
   if($this->fail_barrier){$this->last_error='fixture barrier unavailable';return false;}
   if(isset($this->barriers[(int)$args[1]])){return 0;}
   $row=array_combine(array('subscription_id','actor_id','request_id','requested_at','access_end'),array_slice($args,1,5)); $this->barriers[(int)$row['subscription_id']]=$row; return 1;
  }
  if(false!==strpos($sql,'UPDATE') || false!==strpos($sql,'DELETE')){$this->mutations[]=array('forbidden',$sql);return false;}
  return 1;
 }
}
function reset_cancellation_evidence_fixture() {
 $GLOBALS['wpdb']=new CancellationEvidenceDatabase(); $GLOBALS['ce_user']=7; $GLOBALS['ce_admin']=false; $GLOBALS['ce_status']='active';
 $GLOBALS['ce_meta']=array('_subscrpt_auto_renew'=>1,'_subscrpt_next_date'=>time()+86400,'_subscrpt_order_id'=>1);
 foreach(array('ce_meta_fail','ce_status_fail','ce_comment_fail','ce_mail_fail') as $key){$GLOBALS[$key]=false;} $GLOBALS['ce_hooks']=array();
 $GLOBALS['ce_status_writes']=array(); $GLOBALS['ce_comments']=array();
 $GLOBALS['ce_action_callbacks']=array();$GLOBALS['ce_queued']=array();$GLOBALS['ce_queue_fail']=false;
 $GLOBALS['ce_parent_order']=new class { public function get_payment_method() { return 'stripe'; } };
}
$source=dirname(__DIR__,2).'/plugin/includes/Illuminate/CancellationEvidence.php';
if(!is_file($source)){echo json_encode(array('implementation_missing'=>true));return;}
require dirname(__DIR__,2).'/plugin/includes/Illuminate/Action.php';
require $source;
$service='SpringDevs\\Subscription\\Illuminate\\CancellationEvidence';
$results=array();
reset_cancellation_evidence_fixture();
$results['owner']=$service::request(99,7,'owner-request'); $results['owner_blocked']=$service::blocked(99); $results['owner_barriers']=$GLOBALS['wpdb']->barriers;
$first=$GLOBALS['wpdb']->barriers;
$results['duplicate_before']=array('hooks'=>$GLOBALS['ce_hooks'],'status_writes'=>$GLOBALS['ce_status_writes'],'comments'=>$GLOBALS['ce_comments'],'status'=>$GLOBALS['ce_status'],'meta'=>$GLOBALS['ce_meta'],'events'=>count($GLOBALS['wpdb']->events));
$results['duplicate']=$service::request(99,7,'owner-request'); $service::request(99,7,'second-request');
$results['duplicate_after']=array('hooks'=>$GLOBALS['ce_hooks'],'status_writes'=>$GLOBALS['ce_status_writes'],'comments'=>$GLOBALS['ce_comments'],'status'=>$GLOBALS['ce_status'],'meta'=>$GLOBALS['ce_meta'],'events'=>count($GLOBALS['wpdb']->events));
$results['barrier_immutable']=$first===$GLOBALS['wpdb']->barriers;
$GLOBALS['ce_status']='active'; $GLOBALS['ce_meta']['_subscrpt_auto_renew']=1;
$results['resurrection_blocked']=$service::blocked(99);
foreach(array('foreign'=>array(8,8,false),'guest'=>array(0,0,false),'spoofed'=>array(8,7,false),'admin'=>array(8,8,true)) as $name=>$case){
 reset_cancellation_evidence_fixture(); list($GLOBALS['ce_user'],$actor,$GLOBALS['ce_admin'])=$case;
 $results[$name]=$service::request(99,$actor,$name.'-request'); $results[$name.'_barriers']=count($GLOBALS['wpdb']->barriers);
 $results[$name.'_events']=$GLOBALS['wpdb']->events;
}
reset_cancellation_evidence_fixture(); $GLOBALS['wpdb']->fail_audit_at=2;
$results['outcome_audit_write']=$service::request(99,7,'outcome-audit'); $results['outcome_audit_write_blocked']=$service::blocked(99);
foreach(array('barrier_write'=>'fail_barrier','audit_write'=>'fail_audit','database_read'=>'fail_read','lock'=>'fail_lock') as $name=>$flag){
 reset_cancellation_evidence_fixture(); $GLOBALS['wpdb']->$flag=true;
 $results[$name]=$service::request(99,7,$name); $results[$name.'_blocked']=$service::blocked(99);
}
foreach(array('status_write'=>'ce_status_fail','meta_write'=>'ce_meta_fail','comment_write'=>'ce_comment_fail','mailer'=>'ce_mail_fail') as $name=>$flag){
 reset_cancellation_evidence_fixture(); $GLOBALS[$flag]=true;
 try{$results[$name]=$service::request(99,7,$name);}catch(Throwable $error){$results[$name]=array('threw'=>get_class($error));}
 $results[$name.'_blocked']=$service::blocked(99);
}
foreach(array('repair_status'=>'ce_status_fail','repair_meta'=>'ce_meta_fail') as $name=>$flag){
 reset_cancellation_evidence_fixture(); $GLOBALS[$flag]=true;
 $first_result=$service::request(99,7,$name.'-first'); $original=$GLOBALS['wpdb']->barriers;
 $GLOBALS[$flag]=false;
 $GLOBALS['ce_meta']['_subscrpt_next_date']=time()+30*DAY_IN_SECONDS;
 $second_result=$service::request(99,7,$name.'-retry');
 $results[$name]=array('first'=>$first_result,'second'=>$second_result,'original'=>$original,'final'=>$GLOBALS['wpdb']->barriers,'meta'=>$GLOBALS['ce_meta'],'status'=>$GLOBALS['ce_status'],'blocked'=>$service::blocked(99));
}
reset_cancellation_evidence_fixture(); $GLOBALS['ce_parent_order']=false;
$results['missing_parent']=$service::request(99,7,'missing-parent'); $results['missing_parent_blocked']=$service::blocked(99);
reset_cancellation_evidence_fixture(); $GLOBALS['wpdb']->fail_lock=true;
$first_result=$service::request(99,7,'dispatch-contention'); $original=$GLOBALS['wpdb']->barriers;
$first_blocked=$service::blocked(99); $GLOBALS['wpdb']->fail_lock=false;
$second_result=$service::request(99,7,'dispatch-contention-retry');
$results['dispatch_contention']=array('first'=>$first_result,'first_blocked'=>$first_blocked,'original'=>$original,'second'=>$second_result,'final'=>$GLOBALS['wpdb']->barriers,'status'=>$GLOBALS['ce_status'],'meta'=>$GLOBALS['ce_meta']);
foreach(array('queued_repair','lost_queue_sweep','overdue_sweep','busy_worker') as $name){
 reset_cancellation_evidence_fixture();$GLOBALS['ce_status_fail']=true;$GLOBALS['ce_queue_fail']='lost_queue_sweep'===$name;
 $first_result=$service::request(99,7,$name.'-request');$original=$GLOBALS['wpdb']->barriers;$queued=$GLOBALS['ce_queued'];
 $GLOBALS['ce_status_fail']=false;$GLOBALS['ce_user']=0;
 if('overdue_sweep'===$name){$GLOBALS['wpdb']->barriers[99]['access_end']=time()-60;$original=$GLOBALS['wpdb']->barriers;$GLOBALS['ce_meta']['_ashbi_cancellation_confirmed']=1;}
 if('busy_worker'===$name){$GLOBALS['wpdb']->fail_lock=true;}
 $error=null;
 try{$service::register_hooks();do_action(in_array($name,array('lost_queue_sweep','overdue_sweep'),true)?'subscrpt_hourly_cron':'subscrpt_repair_cancellation',99);}catch(Throwable $exception){$error=get_class($exception).': '.$exception->getMessage();}
 $results[$name]=array('first'=>$first_result,'original'=>$original,'final'=>$GLOBALS['wpdb']->barriers,'meta'=>$GLOBALS['ce_meta'],'status'=>$GLOBALS['ce_status'],'error'=>$error,'queued_before'=>$queued,'queued_after'=>$GLOBALS['ce_queued'],'events'=>$GLOBALS['wpdb']->events);
}
reset_cancellation_evidence_fixture();$GLOBALS['ce_user']=0;$service::repair(99);$service::sweep();
$results['no_intent_repair']=array('barriers'=>$GLOBALS['wpdb']->barriers,'status'=>$GLOBALS['ce_status'],'events'=>$GLOBALS['wpdb']->events,'status_writes'=>$GLOBALS['ce_status_writes']);
reset_cancellation_evidence_fixture();
$details=array('code'=>'<b>received</b>','status'=>'pending','order_id'=>123,'access_end'=>1234,'audit_complete'=>true,'provider_state'=>'pending','password'=>'fixture-secret','email'=>'private@example.test','card_number'=>'4242424242424242','token'=>'fixture-token','comment'=>'unnecessary personal data','nested'=>array('secret'=>'nested-secret'));
$results['record_first']=$service::record('request_received',99,7,'audit-request',$details);
$results['record_second']=$service::record('cancel_failed',99,7,'audit-request',array('code'=>'failed'));
$results['events']=$GLOBALS['wpdb']->events; $results['mutations']=$GLOBALS['wpdb']->mutations;
$GLOBALS['wpdb']->fail_audit=true; $results['record_failure']=$service::record('cancel_failed',99,7,'audit-failure',array());
echo json_encode($results);
