<?php
$code=file_get_contents(dirname(__DIR__).'/lib/sokumiru_api_client.php');
$code=str_replace('declare(strict_types=1);','declare(strict_types=1); namespace TransportTest; use RuntimeException; use Throwable;', $code);
eval('?>'.$code);
eval('namespace TransportTest; function curl_init($u){return (object)["url"=>$u];} function curl_setopt_array($h,$o){$GLOBALS["options"][]=$o;} function curl_exec($h){$r=array_shift($GLOBALS["responses"]);$GLOBALS["current"]=$r;return $r[0];} function curl_errno($h){return $GLOBALS["current"][1];} function curl_getinfo($h,$key=null){$info=$GLOBALS["current"][2];return $key===null?$info:$info["http_code"];} function curl_close($h){}');
foreach (['CURLOPT_RETURNTRANSFER','CURLOPT_CONNECTTIMEOUT','CURLOPT_TIMEOUT','CURLOPT_FAILONERROR','CURLOPT_FOLLOWLOCATION','CURLOPT_HTTPHEADER','CURLOPT_USERAGENT','CURLOPT_HEADERFUNCTION','CURLOPT_REFERER','CURLOPT_IPRESOLVE','CURL_IPRESOLVE_V4','CURLINFO_HTTP_CODE','CURLE_COULDNT_CONNECT','CURLE_OPERATION_TIMEDOUT','CURLE_COULDNT_RESOLVE_HOST'] as $i=>$c) if(!defined($c))define($c,$i+100);
function check($b,$m){if(!$b)throw new RuntimeException($m);}
$c=new TransportTest\SokumiruApiClient('test-key','test-aff','https://sokmil-ad.com/api/v1');
$send=new ReflectionMethod($c,'sendRequest');
$fail=[false,CURLE_OPERATION_TIMEDOUT,['connect_time'=>0,'http_code'=>0]];
$GLOBALS['responses']=[$fail,['{}',0,['connect_time'=>0.1,'http_code'=>200]]];$GLOBALS['options']=[];
check($send->invoke($c,'https://sokmil-ad.com/api/v1/Item')===['{}',200],'retry success');
check(count($GLOBALS['options'])===2 && $GLOBALS['options'][1][CURLOPT_IPRESOLVE]===CURL_IPRESOLVE_V4,'IPv4 retry');
$GLOBALS['responses']=[$fail,$fail];$GLOBALS['options']=[];
try{$send->invoke($c,'https://sokmil-ad.com/api/v1/Item');throw new Exception('should fail');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'IPv4再試行済み'),'connection diagnosis');}
check(count($GLOBALS['options'])===2,'bounded retry');
$GLOBALS['responses']=[[false,CURLE_OPERATION_TIMEDOUT,['connect_time'=>0.1,'http_code'=>0]]];$GLOBALS['options']=[];
try{$send->invoke($c,'https://sokmil-ad.com/api/v1/Item');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'応答待ち'),'response diagnosis');}
check(count($GLOBALS['options'])===1,'no duplicate completed request');
$GLOBALS['responses']=[['denied',0,['connect_time'=>0.1,'http_code'=>403]]];$GLOBALS['options']=[];
check($send->invoke($c,'https://sokmil-ad.com/api/v1/Item')===['denied',403] && count($GLOBALS['options'])===1,'no auth retry');
echo "PASS connection retry, bounded failure, response timeout and HTTP errors\n";
