<?php
function wp_json_file_decode($f,$o=array()){return json_decode(file_get_contents($f),true);}
define('ABSPATH','/');define('DZCOD_DIR',dirname(__DIR__, 2) . '/');define('HOUR_IN_SECONDS',3600);define('DAY_IN_SECONDS',86400);define('MB_IN_BYTES',1048576);function wp_parse_url($u,$c){return parse_url($u,$c);}
function __($s){return $s;} function apply_filters($h,$v){return $v;} function add_action(){} function add_filter(){}
function wp_parse_args($a,$d){return array_merge($d,(array)$a);} function remove_accents($s){return iconv('UTF-8','ASCII//TRANSLIT',$s);}
function sanitize_text_field($s){return trim(strip_tags($s));}
$GLOBALS['OPT']=[];function get_option($k,$d=false){return $GLOBALS['OPT'][$k]??$d;} function update_option($k,$v){$GLOBALS['OPT'][$k]=$v;return true;}
function wp_json_encode($v){return json_encode($v);} function untrailingslashit($s){return rtrim($s,'/');}
$GLOBALS['Q']=[];function wp_safe_remote_request($u,$a){$GLOBALS['LOG'][]=[$a['method'],$u];return array_shift($GLOBALS['Q']);} function is_wp_error($x){return false;}
function wp_remote_retrieve_body($r){return $r['body'];} function wp_remote_retrieve_response_code($r){return $r['code'];} function wp_strip_all_tags($s){return strip_tags($s);}
foreach(['locations','settings','antifraud','courier-api','couriers','prices'] as $f) require DZCOD_DIR."includes/class-dzcod-$f.php";
function check($l,$c){echo ($c?'PASS ':'FAIL ').$l."\n";}

// CSV parsing: template, French headers + semicolons + " DA", partial sheet (58 rows)
$tpl=file_get_contents(DZCOD_DIR.'data/delivery-prices-template.csv');
$r=DZCOD_Prices::parse_csv($tpl); check('template parses 69 rows',count($r)==69 && $r['16']===[600.0,400.0,1]);
$r=DZCOD_Prices::parse_csv("Code;Wilaya;Domicile;Stop desk;Actif\n16 - Alger;Alger;450 DA;300 DA;oui\n1;Adrar;1 400,00;900;non\n");
check('FR headers, ; sep, DA, decimal comma',$r['16']===[450.0,300.0,1] && $r['01']===[1400.0,900.0,0]);
check('bad header → empty',DZCOD_Prices::parse_csv("a,b\n1,2")===[]);

// Google Sheets link conversion
check('edit link → export csv',DZCOD_Prices::csv_url('https://docs.google.com/spreadsheets/d/AbC_123/edit#gid=456')==='https://docs.google.com/spreadsheets/d/AbC_123/export?format=csv&gid=456');
check('pubhtml → pub csv',DZCOD_Prices::csv_url('https://docs.google.com/spreadsheets/d/e/2PACX-x/pubhtml')==='https://docs.google.com/spreadsheets/d/e/2PACX-x/pub?output=csv');
check('other csv URL untouched',DZCOD_Prices::csv_url('https://example.com/p.csv')==='https://example.com/p.csv');

// apply(): legacy courier codes spread to new wilayas; sheet without new wilayas inherits parent
DZCOD_Prices::apply(['28'=>[700.0,500.0,null],'03'=>[900.0,null,null]],true);
$p=DZCOD_Settings::get('prices');
check('M\'Sila 28 set',$p['28']['home']==700.0 && $p['28']['desk']==500.0);
check('Bou Saada 68 inherits 28',$p['68']['home']==700.0 && $p['68']['desk']==500.0);
check('Aflou 59 inherits 03, desk kept',$p['59']['home']==900.0 && $p['59']['desk']==400.0);
check('Alger untouched',$p['16']['home']==600);
DZCOD_Prices::apply(['17'=>[650.0,450.0,1]],false);
$p=DZCOD_Settings::get('prices');
check('sheet: 65,66 inherit Djelfa 17',$p['65']['home']==650.0 && $p['66']['desk']==450.0);
DZCOD_Prices::apply(['17'=>[650.0,450.0,1],'66'=>[999.0,999.0,0]],false);
$p=DZCOD_Settings::get('prices'); check('sheet own row wins for 66',$p['66']['home']==999.0 && $p['66']['enabled']==0 && $p['65']['home']==650.0);

// settings migration from 1.1 + locked fields
$GLOBALS['OPT']=['dzcod_settings'=>['show_address'=>0,'require_address'=>1,'show_quantity'=>0]];DZCOD_Settings::flush();
check('migrated address hidden',DZCOD_Settings::field('address')['show']===0);
check('migrated qty hidden',DZCOD_Settings::field('qty')['show']===0);
check('phone locked required',DZCOD_Settings::field('phone')['required']===1);
$GLOBALS['OPT']=['dzcod_settings'=>['fields'=>['phone'=>['show'=>0,'required'=>0,'label'=>'','placeholder'=>'']],'custom_fields'=>[0=>['label'=>'Taille','type'=>'select','options'=>'S, M ,L','required'=>1],1=>['label'=>''],2=>['label'=>'Couleur','type'=>'select','options'=>'']]]];DZCOD_Settings::flush();
$f=DZCOD_Settings::field('phone');check('locked overrides saved + default label',$f['show']===1&&$f['required']===1&&$f['label']==='Phone number');
$cf=DZCOD_Settings::custom_fields();
check('custom: empty skipped, stable keys',count($cf)==2 && $cf[0]['key']==='dzcod_cf_0' && $cf[1]['key']==='dzcod_cf_2');
check('custom: options trimmed',$cf[0]['options']===['S','M','L']);
check('custom: select w/o options → text',$cf[1]['type']==='text');

// fees parsing (tolerant) via import_courier with mocked HTTP
$GLOBALS['OPT']=['dzcod_couriers'=>['provider'=>'ecotrack','ecotrack_url'=>'https://dhd.ecotrack.dz','ecotrack_token'=>'x']];DZCOD_Settings::flush();
function q($c,$a){$GLOBALS['Q'][]=['code'=>$c,'body'=>json_encode($a)];}
q(200,['livraison'=>[['wilaya_id'=>16,'tarif'=>'400','tarif_stopdesk'=>'250'],['wilaya_id'=>28,'tarif'=>800,'tarif_stopdesk'=>500]]]);
$r=DZCOD_Prices::import_courier(0); $p=DZCOD_Settings::get('prices');
check('ecotrack fees imported: '.$r['message'],$r['ok'] && $p['16']['home']==400.0 && $p['68']['desk']==500.0);
check('ecotrack GET /api/v1/get/fees',end($GLOBALS['LOG'])===['GET','https://dhd.ecotrack.dz/api/v1/get/fees']);
q(200,['livraison'=>[['wilaya'=>16,'prix'=>1]]]);$r=DZCOD_Prices::import_courier(0);
check('unknown format → clear error with sample',!$r['ok'] && strpos($r['message'],'not recognised')!==false);
$GLOBALS['OPT']['dzcod_couriers']=['provider'=>'zrexpress','procolis_token'=>'t','procolis_key'=>'k'];
q(200,[['IDWilaya'=>'5','Domicile'=>'750','Stopdesk'=>'450']]);$r=DZCOD_Prices::import_courier(0);$p=DZCOD_Settings::get('prices');
check('ZR tarification imported → Barika 60 too',$r['ok'] && $p['05']['home']==750.0 && $p['60']['desk']==450.0 && end($GLOBALS['LOG'])[0]==='POST');

// Yalidine chunked, mode of commune prices
$GLOBALS['OPT']['dzcod_couriers']=['provider'=>'yalidine','yalidine_id'=>'i','yalidine_token'=>'t','yalidine_from_wilaya'=>'Tizi Ouzou'];
for($i=0;$i<6;$i++) q(200,['per_commune'=>['1'=>['express_home'=>500,'express_desk'=>350],'2'=>['express_home'=>500,'express_desk'=>350],'3'=>['express_home'=>700,'express_desk'=>null]]]);
$r=DZCOD_Prices::import_courier(0);$p=DZCOD_Settings::get('prices');
check('yalidine chunk 1: '.$r['message'],$r['ok'] && !$r['done'] && $r['next']==6 && $p['01']['home']==500.0 && $p['01']['desk']==350.0);
check('yalidine from=15 (Tizi Ouzou)',strpos(end($GLOBALS['LOG'])[1],'from_wilaya_id=15&to_wilaya_id=6')!==false);
$GLOBALS['Q']=[];for($i=0;$i<4;$i++) q(200,['per_commune'=>[]]);
$r=DZCOD_Prices::import_courier(54); check('yalidine last chunk done: '.$r['message'],$r['done'] && $r['next']==58);
// Security: generic Ecotrack only accepts *.ecotrack.dz
foreach (['https://ecorapide-express.ecotrack.dz/home'=>'https://ecorapide-express.ecotrack.dz','hhd.ecotrack.dz'=>'https://hhd.ecotrack.dz','https://evil.com/x'=>'','https://ecotrack.dz.evil.com'=>'','https://evilecotrack.dz'=>'','http://a.ecotrack.dz@evil.com'=>''] as $in=>$exp) check("ecotrack host $in → '".DZCOD_Courier_API::ecotrack_origin($in)."'",DZCOD_Courier_API::ecotrack_origin($in)===$exp);
