<?php declare(strict_types=1);
namespace hikari_no_yume\touchHLE\app_compatibility_db;
require_once '../include/api.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Allow: POST'); apiError(405,'method_not_allowed','Use POST.'); }
$credential=apiAuthenticateCredential(apiReadToken());
if($credential===NULL){header('WWW-Authenticate: Bearer');apiError(401,'unauthorized','Provide a valid API token.');}
$body=json_decode((string)file_get_contents('php://input'),TRUE,8);
if(!is_array($body))apiError(400,'bad_json','The request body must be a JSON object.');
beginTransaction();
try{
    $userId=createOrGetUserId($credential['identity'],$credential['identity']);
    $note=['created_by'=>$userId,'body'=>$body['body']??NULL];
    foreach(['app_id','version_id','report_id']as$key)if(isset($body[$key]))$note[$key]=$body[$key];
    $noteId=createDeveloperNote($note,$credential['trusted']);
    if($noteId===NULL)throw new ApiSubmissionError('provide a note body and exactly one valid app_id, version_id, or report_id');
    commitTransaction();
}catch(ApiSubmissionError $error){rollbackTransaction();apiError(400,'invalid_submission',$error->getMessage());}
catch(\Throwable $error){rollbackTransaction();apiError(500,'internal_error','The note could not be stored.');}
apiRespond(201,['status'=>$credential['trusted']?'approved':'pending_moderation','note_id'=>$noteId]);
