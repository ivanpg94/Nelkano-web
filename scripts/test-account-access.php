<?php
/** Local transaction-only regression: normal account access without an age gate. */
declare(strict_types=1);
use Drupal\Core\Form\FormState;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\nelkano_home\Controller\AppAuthController;
use Drupal\nelkano_home\Controller\AppSessionController;
use Drupal\nelkano_home\Controller\AuthController;
use Drupal\nelkano_home\Form\NelkanoLoginForm;
use Drupal\nelkano_home\Form\NelkanoRegisterForm;
use Drupal\nelkano_home\Service\AppSessions;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;

if (PHP_SAPI !== 'cli' || realpath(DRUPAL_ROOT) !== '/opt/drupal/web' || getenv('DRUPAL_DB_HOST') !== 'database' || getenv('NELKANO_ACCOUNT_ACCESS_QA') !== '1') {
  throw new RuntimeException('Account access QA is limited to explicit local Docker execution.');
}
$db=\Drupal::database(); $before=(int)$db->select('users','u')->countQuery()->execute()->fetchField();
$mailBefore=$db->select('config','c')->fields('c',['data'])->condition('name','system.mail')->execute()->fetchField();
$transaction=$db->startTransaction(); $originalAccount=\Drupal::currentUser()->getAccount(); $checks=0;
$assert=static function(bool $ok,string $reason)use(&$checks):void{if(!$ok)throw new RuntimeException($reason);$checks++;};
$request=static fn(string $path,array $body=[],string $token='')=>Request::create($path,'POST',[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_HOST'=>'localhost','REMOTE_ADDR'=>'127.0.0.248','HTTP_AUTHORIZATION'=>$token?'Bearer '.$token:''],json_encode($body,JSON_THROW_ON_ERROR));
try {
  \Drupal::currentUser()->setAccount(new AnonymousUserSession());
  \Drupal::configFactory()->getEditable('system.mail')->set('interface',['default'=>'test_mail_collector','nelkano_home_account_verify'=>'test_mail_collector'])->save();
  $collector=\Drupal::service('plugin.manager.mail')->getInstance(['module'=>'nelkano_home','key'=>'account_verify']);
  $assert($collector instanceof \Drupal\Core\Mail\Plugin\Mail\TestMailCollector,'Mail must be intercepted before registration.');
  $marker='qa-access-'.bin2hex(random_bytes(6)); $password=bin2hex(random_bytes(16));
  $registration=['name'=>'Account access fixture','email'=>$marker.'@example.invalid','password'=>$password,'password_confirm'=>$password,'terms'=>TRUE];
  $v1=new AppAuthController(); $v2=new AppSessionController();
  $response=$v1->register($request('/api/nelkano/auth/register',array_replace($registration,['terms'=>FALSE])));
  $assert($response->getStatusCode()===400,'Terms must still be required.');
  $response=$v1->register($request('/api/nelkano/auth/register',$registration));
  $assert($response->getStatusCode()===201,'Registration must accept the normal form without a separate age field.');
  $created=\Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['mail'=>$registration['email']]); $created=reset($created);
  $assert($created instanceof User && !$created->isActive(),'Email verification remains required.');
  $assert(\Drupal::service('user.data')->get('nelkano_home',(int)$created->id(),'adult_account_attestation')===NULL,'Registration must not collect an age declaration.');
  $response=$v2->login($request('/api/nelkano/v2/auth/login',['email'=>$registration['email'],'password'=>$password]));
  $assert($response->getStatusCode()===401,'An unverified account must still be refused.');
  $legacy=User::create(['name'=>$marker.'-existing@example.invalid','mail'=>$marker.'-existing@example.invalid','pass'=>$password,'status'=>1]);$legacy->save();
  $credentials=['email'=>$legacy->getEmail(),'password'=>$password,'device'=>['id'=>'normal-account-access-qa']];
  $response=$v2->login($request('/api/nelkano/v2/auth/login',$credentials));
  $assert($response->getStatusCode()===200,'Existing accounts must sign in without an age declaration.');
  $tokens=json_decode($response->getContent(),TRUE,flags:JSON_THROW_ON_ERROR);
  $assert((int)AppSessions::accountFromAccessToken($tokens['access_token'])?->id()===(int)$legacy->id(),'Existing active tokens must work without age data.');
  $assert($v2->me($request('/api/nelkano/v2/auth/me',[],$tokens['access_token']))->getStatusCode()===200,'The account endpoint must accept the active token.');
  $refresh=AppSessions::refresh($tokens['refresh_token'],'normal-account-access-qa');
  $assert($refresh['status']==='ok','Refresh rotation must work without age data.');
  $assert(AppSessions::refresh($tokens['refresh_token'],'normal-account-access-qa')['status']==='retry','Refresh reuse grace must remain enforced.');
  $assert(AppSessions::accountFromAccessToken('nkat_'.str_repeat('0',64))===NULL,'Unknown tokens must remain invalid.');
  $response=$v2->login($request('/api/nelkano/v2/auth/login',array_replace($credentials,['password'=>'wrong-fixture-password'])));
  $assert($response->getStatusCode()===401,'Wrong passwords must still be refused.');
  $response=$v1->login($request('/api/nelkano/auth/login',$credentials));
  $assert($response->getStatusCode()===200,'Legacy login must not require an age declaration.');
  $legacyToken=json_decode($response->getContent(),TRUE,flags:JSON_THROW_ON_ERROR)['token'];
  $assert($v1->me($request('/api/nelkano/auth/me',[],$legacyToken))->getStatusCode()===200,'Legacy bearer access must retain password/token validation without an age gate.');
  $assert(\Drupal::service('user.data')->get('nelkano_home',(int)$legacy->id(),'adult_account_attestation')===NULL,'Access must not invent a declaration for existing users.');
  $login=NelkanoLoginForm::create(\Drupal::getContainer()); $loginForm=$login->buildForm([],new FormState(),'es');
  $assert(!isset($loginForm['adult_confirmed']),'Web login must have no age checkbox.');
  $state=(new FormState())->setValues(['name'=>$legacy->getEmail(),'pass'=>$password]); $login->validateForm($loginForm,$state);
  $assert(!$state->hasAnyErrors() && (int)$state->get('uid')===(int)$legacy->id(),'Normal web login validation must accept existing credentials without age input.');
  $register=NelkanoRegisterForm::create(\Drupal::getContainer());$registerForm=$register->buildForm([],new FormState(),'es');
  $assert(!isset($registerForm['adult_confirmed']) && str_contains((string)$registerForm['terms']['#description'],'14 años'),'Registration must show the simple 14+ policy without a separate checkbox.');
  $assert($registerForm['terms']['#required']===TRUE,'The normal terms checkbox remains required.');
  \Drupal::currentUser()->setAccount($legacy); $controller=AuthController::create(\Drupal::getContainer());
  foreach(['/user/confirm-age'=>'/user/stream','/en/user/confirm-age'=>'/en/user/stream'] as $path=>$expected){
    \Drupal::service('request_stack')->push(Request::create($path));
    try{$response=$controller->accountAccessRedirect();$assert($response->isRedirect($expected),'Old age URLs must redirect directly to the account without a confirmation screen.');}
    finally{\Drupal::service('request_stack')->pop();}
  }
  \Drupal::currentUser()->setAccount(new AnonymousUserSession());
  foreach(['/user/confirm-age'=>'/user/login','/en/user/confirm-age'=>'/en/user/login'] as $path=>$expected){
    \Drupal::service('request_stack')->push(Request::create($path));
    try{$response=$controller->accountAccessRedirect();$assert($response->isRedirect($expected) && $response->headers->hasCacheControlDirective('no-store') && $response->headers->hasCacheControlDirective('private'),'Anonymous old age URLs must privately redirect to normal login.');}
    finally{\Drupal::service('request_stack')->pop();}
  }
  foreach(['nelkano_home.account_access_redirect_es','nelkano_home.account_access_redirect_en'] as $name){
    $route=\Drupal::service('router.route_provider')->getRouteByName($name);
    $assert($route->getRequirement('_access')==='TRUE' && $route->getRequirement('_user_is_logged_in')===NULL,'Compatibility redirects must permit anonymous access.');
  }
  $assert(!\Drupal::hasService('nelkano_home.adult_account_subscriber'),'The age-blocking event subscriber must be gone.');
  AppSessions::revokeByToken($refresh['tokens']['access_token']);
  $assert(AppSessions::accountFromAccessToken($refresh['tokens']['access_token'])===NULL,'Revocation must remain enforced.');
}
finally { \Drupal::currentUser()->setAccount($originalAccount);$transaction->rollBack(); }
$assert((int)$db->select('users','u')->countQuery()->execute()->fetchField()===$before,'Fixture accounts must be rolled back.');
$assert($db->select('config','c')->fields('c',['data'])->condition('name','system.mail')->execute()->fetchField()===$mailBefore,'Mail configuration must stay unchanged.');
echo json_encode(['passed'=>TRUE,'assertions'=>$checks,'fixturesRolledBack'=>TRUE,'realMailSent'=>FALSE,'existingAccountsModified'=>FALSE,'scope'=>'Account login, registration, token rotation/revocation and removed web age gate'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";