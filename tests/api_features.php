<?php declare(strict_types=1);
const SITE_DB_PATH = ':memory:';
const SITE_BASE_PATH = '';
const APP_IDENTITY_FIELD = 'bundle_identifier';
const API_TOKENS = [
 'legacy-token' => 'agent:legacy',
 'trusted-token' => ['identity' => 'agent:taphle-lead', 'trusted' => TRUE],
];
const APP_EXTRA_FIELDS = ['bundle_identifier' => ['name'=>'Bundle identifier','required'=>TRUE]];
const VERSION_EXTRA_FIELDS = ['bundle_version' => ['name'=>'Bundle version','required'=>TRUE]];
const REPORT_EXTRA_FIELDS = [
 'source_type'=>['name'=>'Source type','required'=>TRUE,'options'=>['agent'=>'Agent','telemetry'=>'Telemetry']],
 'source_name'=>['name'=>'Source name','required'=>TRUE],
 'platform'=>['name'=>'Platform','required'=>TRUE,'options'=>['Windows'=>'Windows','Linux'=>'Linux','macOS'=>'macOS','Android'=>'Android','iOS'=>'iOS']],
 'architecture'=>['name'=>'Architecture','required'=>TRUE],
 'os_version'=>['name'=>'OS version','required'=>TRUE],
 'taphle_commit'=>['name'=>'tapHLE commit','required'=>TRUE,'pattern'=>'/\\A[0-9a-f]{40}\\z/D'],
 'artifact_sha256'=>['name'=>'Product SHA-256','required'=>TRUE,'pattern'=>'/\\A[0-9a-f]{64}\\z/D'],
 'app_artifact_sha256'=>['name'=>'App SHA-256','required'=>TRUE,'pattern'=>'/\\A[0-9a-f]{64}\\z/D'],
 'build_provenance'=>['name'=>'Build provenance','required'=>TRUE],
 'build_profile'=>['name'=>'Build profile','required'=>TRUE,'options'=>['debug'=>'debug','release'=>'release']],
 'verification_type'=>['name'=>'Verification type','required'=>TRUE,'options'=>['compatibility'=>'Compatibility','release_verification'=>'Release verification']],
 'release_version'=>['name'=>'Release version'],
 'frontier'=>['name'=>'Frontier'],
];
require_once __DIR__.'/../include/util.php';
require_once __DIR__.'/../include/queries.php';
require_once __DIR__.'/../include/api.php';
use function hikari_no_yume\touchHLE\app_compatibility_db\apiApplyTrustedApproval;
use function hikari_no_yume\touchHLE\app_compatibility_db\apiAuthenticateCredential;
use function hikari_no_yume\touchHLE\app_compatibility_db\apiListApps;
use function hikari_no_yume\touchHLE\app_compatibility_db\apiPendingLimitExceeded;
use function hikari_no_yume\touchHLE\app_compatibility_db\apiListReleaseVerifications;
use function hikari_no_yume\touchHLE\app_compatibility_db\apiValidateReportSemantics;
use function hikari_no_yume\touchHLE\app_compatibility_db\canViewReportScreenshot;
use function hikari_no_yume\touchHLE\app_compatibility_db\createReport;
use function hikari_no_yume\touchHLE\app_compatibility_db\getReportScreenshotImage;
use function hikari_no_yume\touchHLE\app_compatibility_db\reportScreenshotCacheControl;
use function hikari_no_yume\touchHLE\app_compatibility_db\initDb;
use function hikari_no_yume\touchHLE\app_compatibility_db\query;
use function hikari_no_yume\touchHLE\app_compatibility_db\validateExtraFields;
function same(mixed $e,mixed $a,string $m):void{if($e!==$a)throw new RuntimeException("$m expected=".var_export($e,TRUE)." actual=".var_export($a,TRUE));}
function yes(bool $a,string $m):void{if(!$a)throw new RuntimeException($m);}
function throws(callable $c,string $m):void{try{$c();}catch(Throwable){return;}throw new RuntimeException($m);}
function report(array $o=[]):array{return array_replace([
 'source_type'=>'agent','source_name'=>'tapHLE Lead','platform'=>'Windows','architecture'=>'x86_64','os_version'=>'11 24H2',
 'taphle_commit'=>str_repeat('a',40),'artifact_sha256'=>str_repeat('b',64),'app_artifact_sha256'=>str_repeat('c',64),
 'build_provenance'=>'clean checkout; rust 1.97.1; release workflow','build_profile'=>'release',
 'verification_type'=>'compatibility','frontier'=>'gameplay loop persists'], $o);}
@unlink(SITE_DB_PATH);initDb();global $db;$db->exec((string)file_get_contents(__DIR__.'/../schema.sql'));
same(['identity'=>'agent:legacy','trusted'=>FALSE],apiAuthenticateCredential('legacy-token'),'legacy credential');
same(['identity'=>'agent:taphle-lead','trusted'=>TRUE],apiAuthenticateCredential('trusted-token'),'trusted credential');
same(NULL,apiAuthenticateCredential('wrong-token'),'unknown credential');
yes(validateExtraFields(REPORT_EXTRA_FIELDS,report()),'valid provenance');
$missing=report();unset($missing['platform']);yes(!validateExtraFields(REPORT_EXTRA_FIELDS,$missing),'missing required field rejected server-side');
yes(!validateExtraFields(REPORT_EXTRA_FIELDS,report(['taphle_commit'=>'abc12345'])),'reject short commit');
yes(!validateExtraFields(REPORT_EXTRA_FIELDS,report(['artifact_sha256'=>str_repeat('z',64)])),'reject bad hash');
apiValidateReportSemantics(report(),3);
throws(fn()=>apiValidateReportSemantics(report(),4),'agent cap');
throws(fn()=>apiValidateReportSemantics(report(['source_type'=>'human']),3),'API cannot claim human');
throws(fn()=>apiValidateReportSemantics(report(['verification_type'=>'release_verification']),3),'release version required');
apiValidateReportSemantics(report(['verification_type'=>'release_verification','release_version'=>'0.2.4']),3);
query("INSERT INTO users(user_id,external_user_id,external_username) VALUES(1,'agent:test','agent:test')");
query("INSERT INTO apps(app_id,created,created_by,approved,approved_by,name,extra) VALUES(1,datetime(),1,datetime(),1,'Example','{\"bundle_identifier\":\"com.example.app\"}')");
query("INSERT INTO versions(version_id,app_id,created,created_by,approved,approved_by,name,extra) VALUES(1,1,datetime(),1,datetime(),1,'1.0','{\"bundle_version\":\"1.0\"}')");
$insert=function(int $id,int $rating,array $extra,bool $approved=TRUE)use($db):void{$s=$db->prepare('INSERT INTO reports(report_id,version_id,created,created_by,approved,approved_by,rating,extra) VALUES(:id,1,datetime(),1,:approved,:by,:rating,:extra)');$s->execute([':id'=>$id,':approved'=>$approved?'2026-08-24 20:00:00':NULL,':by'=>$approved?1:NULL,':rating'=>$rating,':extra'=>json_encode($extra)]);};
$insert(1,3,report());
$insert(2,2,report(['platform'=>'Linux','os_version'=>'Ubuntu 26.04','artifact_sha256'=>str_repeat('d',64)]));
$release=report(['platform'=>'macOS','os_version'=>'15.6','artifact_sha256'=>str_repeat('e',64),'verification_type'=>'release_verification','release_version'=>'0.2.4']);
$insert(3,3,$release);$insert(4,3,array_replace($release,['platform'=>'iOS']),FALSE);$insert(6,2,['source_type'=>'agent','source_name'=>'legacy agent','taphle_version'=>'abcdef12']);
$apps=apiListApps();same(['Linux'=>2,'Windows'=>3],$apps[0]['ratings_by_platform'],'release reconfirmations must not change compatibility ratings');
$matrix=apiListReleaseVerifications('0.2.4',str_repeat('a',40));same(1,count($matrix),'approved matrix only');
same('macOS',$matrix[0]['platform'],'matrix platform');same(str_repeat('e',64),$matrix[0]['artifact_sha256'],'matrix product hash');same('com.example.app',$matrix[0]['bundle_identifier'],'matrix app identity');same('agent:test',$matrix[0]['submitter_identity'],'matrix submitter identity');same('agent',$matrix[0]['source_type'],'matrix producer type');same('tapHLE Lead',$matrix[0]['source_name'],'matrix producer name');
query("INSERT INTO apps(app_id,created,created_by,name,extra) VALUES(2,datetime(),1,'Pending','{\"bundle_identifier\":\"com.example.pending\"}')");
query("INSERT INTO versions(version_id,app_id,created,created_by,name,extra) VALUES(2,2,datetime(),1,'1.0','{\"bundle_version\":\"1.0\"}')");
query('INSERT INTO reports(report_id,version_id,created,created_by,rating,extra) VALUES(5,2,datetime(),1,3,:extra)',[':extra'=>json_encode(report())]);yes(!apiPendingLimitExceeded(TRUE,1,1),'trusted credential bypasses pending limit');yes(apiPendingLimitExceeded(FALSE,1,1),'ordinary credential obeys pending limit');
apiApplyTrustedApproval(TRUE,1,2,FALSE,2,FALSE,5);
$a=query('SELECT (SELECT approved FROM apps WHERE app_id=2) app,(SELECT approved FROM versions WHERE version_id=2) version,(SELECT approved FROM reports WHERE report_id=5) report')[0];
yes($a['app']!==NULL&&$a['version']!==NULL&&$a['report']!==NULL,'trusted hierarchy approval');
$invalidReleaseReport=createReport(['created_by'=>1,'version_id'=>1,'rating'=>3,'extra'=>report(['verification_type'=>'release_verification']),'screenshot'=>'']);same(NULL,$invalidReleaseReport,'web-form model must require release_version too');$overCapReport=createReport(['created_by'=>1,'version_id'=>1,'rating'=>4,'extra'=>report(),'screenshot'=>'']);same(NULL,$overCapReport,'common model must cap agent reports at three stars');same(NULL,getReportScreenshotImage(9999),'missing screenshot returns null');same(TRUE,canViewReportScreenshot(NULL,['approved'=>'2026-08-24']),'approved screenshot public');same('public, max-age=31536000',reportScreenshotCacheControl(['approved'=>'2026-08-24']),'approved screenshot cache policy');same(FALSE,canViewReportScreenshot(NULL,['approved'=>NULL]),'pending screenshot private');same('private, no-store',reportScreenshotCacheControl(['approved'=>NULL]),'pending screenshot cache policy');same(TRUE,canViewReportScreenshot(['external_user_id'=>'github:1'],['approved'=>NULL]),'signed-in user may inspect pending screenshot');
$jpeg="\xFF\xD8\xFF\xD9";$screenshotId=createReport(['created_by'=>1,'version_id'=>1,'rating'=>3,'extra'=>report(),'screenshot'=>'data:image/jpeg;base64,'.base64_encode($jpeg)]);yes(is_int($screenshotId),'screenshot report created');same($jpeg,getReportScreenshotImage($screenshotId),'optional screenshot round trip');
query("INSERT INTO users(user_id,external_user_id,external_username) VALUES(2,'agent:other','agent:other')");query("INSERT INTO apps(app_id,created,created_by,name,extra) VALUES(3,datetime(),2,'Other','{\"bundle_identifier\":\"com.example.other\"}')");query("INSERT INTO versions(version_id,app_id,created,created_by,name,extra) VALUES(3,3,datetime(),2,'1.0','{\"bundle_version\":\"1.0\"}')");query('INSERT INTO reports(report_id,version_id,created,created_by,rating,extra) VALUES(99,3,datetime(),1,3,:extra)',[':extra'=>json_encode(report())]);throws(fn()=>apiApplyTrustedApproval(TRUE,1,3,FALSE,3,FALSE,99),'trusted credential cannot publish another submitter hierarchy');
@unlink(SITE_DB_PATH);echo "api feature tests passed\n";
