<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
ini_set('session.use_strict_mode','1');
session_set_cookie_params(['httponly'=>true,'secure'=>!empty($_SERVER['HTTPS']),'samesite'=>'Lax']);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
function out(array $a,int $code=200): never { http_response_code($code); echo json_encode($a,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE); exit; }
function db(): PDO { static $p=null; if($p===null){$p=new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);} return $p; }
function body(): array { $x=json_decode(file_get_contents('php://input'),true); return is_array($x)?$x:[]; }
function need_user(): array { if(empty($_SESSION['uid'])) out(['ok'=>false,'error'=>'Please sign in again.'],401); $s=db()->prepare('SELECT id,name,email,role,status FROM users WHERE id=?');$s->execute([$_SESSION['uid']]);$u=$s->fetch();if(!$u||$u['status']!=='active'){session_destroy();out(['ok'=>false,'error'=>'Account is inactive.'],403);}return $u; }
function role(array $u,array $roles): void { if(!in_array($u['role'],$roles,true))out(['ok'=>false,'error'=>'You do not have permission for this action.'],403); }
function log_event(string $event): void { $u=$_SESSION['uid']??null; $s=db()->prepare('INSERT INTO activity_logs(actor_id,event) VALUES(?,?)');$s->execute([$u,$event]); }
function valid_email_role(string $email,string $role): bool { $domain=$role==='student'?'@mitsgwl.ac.in':($role==='teacher'?'@mitsgwalior.in':'');return $domain!=='' && str_ends_with(strtolower($email),$domain) && filter_var($email,FILTER_VALIDATE_EMAIL); }
function run_engine(int $total,int $correct,int $wrong,float $penalty): array {
 if(!is_file(ENGINE_PATH)) throw new RuntimeException('C++ engine is not compiled. Follow README setup steps.');
 $cmd=escapeshellarg(ENGINE_PATH).' score '.escapeshellarg((string)$total).' '.escapeshellarg((string)$correct).' '.escapeshellarg((string)$wrong).' '.escapeshellarg((string)$penalty);
 $raw=shell_exec($cmd.' 2>&1');$j=json_decode((string)$raw,true);if(!is_array($j))throw new RuntimeException('C++ scoring engine failed.');return $j;
}
try {
 $b=body();$a=(string)($b['action']??'');
 if($a==='get_developers'){
  db()->exec("INSERT IGNORE INTO project_developers(slot,name,title,bio) VALUES (1,'Project Lead','Lead Developer','Leading the development of the MITS Quiz Portal.'),(2,'Developer 2','Project Developer','Contributing to the Development of Intelligence Quiz Portal.'),(3,'Developer 3','Project Developer','Contributing to the Development of Intelligence Quiz Portal.')");
  $rows=db()->query('SELECT slot,name,title,bio,photo_data,email,github,linkedin,instagram FROM project_developers ORDER BY slot')->fetchAll();
  $rows=array_map(fn($x)=>['slot'=>(int)$x['slot'],'name'=>$x['name'],'title'=>$x['title'],'bio'=>$x['bio'],'photo'=>$x['photo_data'],'email'=>$x['email'],'github'=>$x['github'],'linkedin'=>$x['linkedin'],'instagram'=>$x['instagram']],$rows);
  out(['ok'=>true,'items'=>$rows]);
 }
 if($a==='me'){if(empty($_SESSION['uid']))out(['ok'=>true,'user'=>null]);out(['ok'=>true,'user'=>need_user()]);}
 if($a==='register'){
  $name=trim((string)($b['name']??''));$email=strtolower(trim((string)($b['email']??'')));$role=(string)($b['role']??'');$pass=(string)($b['password']??'');
  if(mb_strlen($name)<2||mb_strlen($name)>100||!valid_email_role($email,$role)||strlen($pass)<10)out(['ok'=>false,'error'=>'Enter a valid name, institutional email and password of at least 10 characters.'],422);
  $status=$role==='teacher'?'pending':'active';$s=db()->prepare('INSERT INTO users(name,email,password_hash,role,status) VALUES(?,?,?,?,?)');$s->execute([$name,$email,password_hash($pass,PASSWORD_DEFAULT),$role,$status]);log_event('New '.$role.' registration: '.$email);
  out(['ok'=>true,'message'=>$status==='pending'?'Faculty request submitted. Admin approval is required.':'Student account created. You can sign in now.']);
 }
 if($a==='login'){
  $email=strtolower(trim((string)($b['email']??'')));$pass=(string)($b['password']??'');$ip=substr($_SERVER['REMOTE_ADDR']??'unknown',0,45);
  $q=db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip_address=? AND success=0 AND attempted_at>DATE_SUB(NOW(),INTERVAL ? MINUTE)');$q->execute([$ip,LOCK_MINUTES]);if((int)$q->fetchColumn()>=MAX_LOGIN_ATTEMPTS)out(['ok'=>false,'error'=>'Too many attempts. Please try again in 15 minutes.'],429);
  $s=db()->prepare('SELECT * FROM users WHERE email=?');$s->execute([$email]);$u=$s->fetch();$ok=$u&&password_verify($pass,$u['password_hash'])&&$u['status']==='active';
  db()->prepare('INSERT INTO login_attempts(ip_address,email,success) VALUES(?,?,?)')->execute([$ip,$email,$ok?1:0]);
  if(!$ok)out(['ok'=>false,'error'=>'Invalid credentials or account awaiting approval.'],401);
  session_regenerate_id(true);$_SESSION['uid']=(int)$u['id'];log_event('Successful sign in');out(['ok'=>true,'user'=>['id'=>$u['id'],'name'=>$u['name'],'email'=>$u['email'],'role'=>$u['role']]]);
 }
 if($a==='logout'){if(!empty($_SESSION['uid']))log_event('Signed out');$_SESSION=[];if(ini_get('session.use_cookies')){$p=session_get_cookie_params();setcookie(session_name(),'',time()-42000,$p['path'],$p['domain'],$p['secure'],$p['httponly']);}session_destroy();out(['ok'=>true]);}
 $u=need_user();$uid=(int)$u['id'];$r=$u['role'];
 if($a==='page_data'){
  $page=(string)($b['page']??'dashboard');$items=[];$stats=[];
  if($page==='dashboard'){
   if($r==='student'){
    $q=db()->query("SELECT id,title,subject,description,duration,(SELECT COUNT(*) FROM quiz_questions qq WHERE qq.quiz_id=quizzes.id) question_count FROM quizzes WHERE status='published' ORDER BY created_at DESC LIMIT 5");$items=array_map(fn($x)=>$x+['subtitle'=>$x['subject'],'status'=>'LIVE'],$q->fetchAll());
    $s=db()->prepare('SELECT COUNT(*) FROM quiz_attempts WHERE student_id=?');$s->execute([$uid]);$attempts=(int)$s->fetchColumn();
    $stats=['focus'=>0,'attempts'=>$attempts,'accuracy'=>0,'streak'=>0];
   }elseif($r==='teacher'){
    $s=db()->prepare('SELECT COUNT(*) FROM teacher_students WHERE teacher_id=? AND status="active"');$s->execute([$uid]);$students=(int)$s->fetchColumn();
    $s=db()->prepare('SELECT COUNT(*) FROM quizzes WHERE teacher_id=?');$s->execute([$uid]);$quizzes=(int)$s->fetchColumn();
    $s=db()->prepare('SELECT COUNT(*) FROM resources WHERE teacher_id=?');$s->execute([$uid]);$resources=(int)$s->fetchColumn();
    $s=db()->prepare('SELECT COUNT(*) FROM support_tickets WHERE status="open"');$doubts=(int)$s->fetchColumn();
    $s=db()->prepare('SELECT id,title,subject,status FROM quizzes WHERE teacher_id=? ORDER BY id DESC LIMIT 5');$s->execute([$uid]);$items=$s->fetchAll();$stats=['students'=>$students,'quizzes'=>$quizzes,'resources'=>$resources,'doubts'=>$doubts];
   }else{
    $stats=[];foreach(['users','students','teachers'] as $t){$sql=$t==='users'?'SELECT COUNT(*) FROM users':"SELECT COUNT(*) FROM users WHERE role='".($t==='students'?'student':'teacher')."'";$stats[$t]=(int)db()->query($sql)->fetchColumn();}
    $stats['tickets']=(int)db()->query("SELECT COUNT(*) FROM support_tickets WHERE status='open'")->fetchColumn();
    $items=db()->query('SELECT event,created_at FROM activity_logs ORDER BY id DESC LIMIT 6')->fetchAll();
   }
  }elseif($page==='quizzes'){
   if($r==='student'){$s=db()->prepare("SELECT q.id,q.title,q.subject,q.description,q.duration,u.name teacher_name,(SELECT COUNT(*) FROM quiz_questions z WHERE z.quiz_id=q.id) question_count FROM quizzes q JOIN users u ON u.id=q.teacher_id WHERE q.status='published' AND (q.target='all' OR EXISTS(SELECT 1 FROM teacher_students ts WHERE ts.teacher_id=q.teacher_id AND ts.student_id=? AND ts.status='active')) ORDER BY q.id DESC");$s->execute([$uid]);$items=$s->fetchAll();}
   elseif($r==='teacher'){$s=db()->prepare('SELECT id,title,subject,description,duration,(SELECT COUNT(*) FROM quiz_questions z WHERE z.quiz_id=quizzes.id) question_count FROM quizzes WHERE teacher_id=? ORDER BY id DESC');$s->execute([$uid]);$items=$s->fetchAll();}
   else $items=db()->query('SELECT q.id,q.title,q.subject,q.status,q.duration,(SELECT COUNT(*) FROM quiz_questions z WHERE z.quiz_id=q.id) question_count FROM quizzes q ORDER BY q.id DESC')->fetchAll();
  }elseif(in_array($page,['lectures','library','resources'],true)){
   $items=db()->query("SELECT r.*,u.name teacher_name FROM resources r JOIN users u ON u.id=r.teacher_id ORDER BY r.id DESC")->fetchAll();
  }elseif($page==='students'){
   role($u,['teacher']);$s=db()->prepare("SELECT u.id,u.name,u.email,u.role,ts.id connection_id,ts.status FROM users u JOIN teacher_students ts ON ts.student_id=u.id WHERE ts.teacher_id=? ORDER BY u.name");$s->execute([$uid]);$items=$s->fetchAll();
  }elseif($page==='teachers'){
   role($u,['student']);$items=db()->query("SELECT id,name,email,role,status FROM users WHERE role='teacher' AND status='active' ORDER BY name")->fetchAll();
  }elseif($page==='users'){
   role($u,['admin']);$items=db()->query('SELECT id,name,email,role,status FROM users ORDER BY id DESC')->fetchAll();
  }elseif(in_array($page,['mentor','doubts','tickets'],true)){
   if($r==='student'){$s=db()->prepare('SELECT id,subject,category,message,reply,status FROM support_tickets WHERE student_id=? ORDER BY id DESC');$s->execute([$uid]);$items=$s->fetchAll();}
   else {role($u,['teacher','admin']);$items=db()->query('SELECT t.*,u.name,u.email FROM support_tickets t JOIN users u ON u.id=t.student_id ORDER BY t.id DESC')->fetchAll();}
  }elseif($page==='activity'){role($u,['admin']);$items=db()->query('SELECT l.event,l.created_at,u.name actor FROM activity_logs l LEFT JOIN users u ON u.id=l.actor_id ORDER BY l.id DESC LIMIT 100')->fetchAll();
  }elseif($page==='developers'){
   db()->exec("INSERT IGNORE INTO project_developers(slot,name,title,bio) VALUES (1,'Project Lead','Lead Developer','Leading the development of the MITS Quiz Portal.'),(2,'Developer 2','Project Developer','Contributing to the Development of Intelligence Quiz Portal.'),(3,'Developer 3','Project Developer','Contributing to the Development of Intelligence Quiz Portal.')");
   $items=db()->query('SELECT slot,name,title,bio,photo_data,email,github,linkedin,instagram FROM project_developers ORDER BY slot')->fetchAll();
   $items=array_map(fn($x)=>['slot'=>(int)$x['slot'],'name'=>$x['name'],'title'=>$x['title'],'bio'=>$x['bio'],'photo'=>$x['photo_data'],'email'=>$x['email'],'github'=>$x['github'],'linkedin'=>$x['linkedin'],'instagram'=>$x['instagram']],$items);
   $stats['can_edit']=defined('PROJECT_LEAD_EMAIL') && PROJECT_LEAD_EMAIL!=='SET_FIRST_DEVELOPER_LOGIN_EMAIL_HERE' && strtolower($u['email'])===strtolower(PROJECT_LEAD_EMAIL);
  }
  out(['ok'=>true,'items'=>$items,'stats'=>$stats]);
 }
 if($a==='save_developer_profile'){
  if(!defined('PROJECT_LEAD_EMAIL') || PROJECT_LEAD_EMAIL==='SET_FIRST_DEVELOPER_LOGIN_EMAIL_HERE' || strtolower($u['email'])!==strtolower(PROJECT_LEAD_EMAIL)) out(['ok'=>false,'error'=>'Only the configured first project developer can edit developer profiles.'],403);
  $slot=(int)($b['slot']??0);$name=trim((string)($b['name']??''));$title=trim((string)($b['title']??'Project Developer'));$bio=trim((string)($b['bio']??''));$email=trim((string)($b['email']??''));
  if($slot<1||$slot>3||$name===''||mb_strlen($name)>100||mb_strlen($title)>100||mb_strlen($bio)>500||($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))) out(['ok'=>false,'error'=>'Please check the profile fields.'],422);
  $photo=(string)($b['photo']??'');if($photo!=='' && (!preg_match('#^data:image/(png|jpeg|webp);base64,[A-Za-z0-9+/=]+$#',$photo)||strlen($photo)>1250000)) out(['ok'=>false,'error'=>'Photo must be a JPG, PNG or WebP image under 900 KB.'],422);
  $links=[];foreach(['github','linkedin','instagram'] as $key){$v=trim((string)($b[$key]??''));if($v!==''&&(!filter_var($v,FILTER_VALIDATE_URL)||!preg_match('#^https://#i',$v)))out(['ok'=>false,'error'=>'Social profile links must be valid HTTPS URLs.'],422);$links[$key]=$v;}
  db()->prepare("INSERT INTO project_developers(slot,name,title,bio,photo_data,email,github,linkedin,instagram,updated_by) VALUES(?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),title=VALUES(title),bio=VALUES(bio),photo_data=IF(VALUES(photo_data) IS NULL OR VALUES(photo_data)='',photo_data,VALUES(photo_data)),email=VALUES(email),github=VALUES(github),linkedin=VALUES(linkedin),instagram=VALUES(instagram),updated_by=VALUES(updated_by)")->execute([$slot,$name,$title,$bio,$photo,$email,$links['github'],$links['linkedin'],$links['instagram'],$uid]);
  log_event('Updated project developer profile slot '.$slot);out(['ok'=>true]);
 }
 if($a==='create_quiz'){
  role($u,['teacher']);$title=trim((string)($b['title']??''));$subject=trim((string)($b['subject']??''));$qs=$b['questions']??[];
  if($title===''||$subject===''||!is_array($qs)||count($qs)<1||count($qs)>100)out(['ok'=>false,'error'=>'Quiz needs a title, subject and 1–100 questions.'],422);
  db()->beginTransaction();$s=db()->prepare("INSERT INTO quizzes(teacher_id,title,subject,description,duration,negative_penalty,status) VALUES(?,?,?,?,?,?, 'published')");$s->execute([$uid,$title,$subject,substr((string)($b['description']??''),0,500),max(1,min(180,(int)($b['duration']??10))),max(0,min(1,(float)($b['negative']??0)))]);$qid=(int)db()->lastInsertId();
  $ins=db()->prepare('INSERT INTO quiz_questions(quiz_id,question_text,option_a,option_b,option_c,option_d,correct_option) VALUES(?,?,?,?,?,?,?)');
  foreach($qs as $q){$opts=$q['options']??[];if(trim((string)($q['question']??''))===''||count($opts)!==4)throw new RuntimeException('Each question needs four options.');$ins->execute([$qid,$q['question'],$opts[0],$opts[1],$opts[2],$opts[3],max(0,min(3,(int)$q['correct']))]);}
  db()->commit();log_event('Published quiz: '.$title);out(['ok'=>true]);
 }
 if($a==='get_quiz'){
  role($u,['student']);$id=(int)($b['id']??0);$s=db()->prepare("SELECT q.*,u.name teacher_name FROM quizzes q JOIN users u ON u.id=q.teacher_id WHERE q.id=? AND q.status='published'");$s->execute([$id]);$q=$s->fetch();if(!$q)out(['ok'=>false,'error'=>'Quiz not found.'],404);
  $s=db()->prepare('SELECT id,question_text question,option_a,option_b,option_c,option_d FROM quiz_questions WHERE quiz_id=?');$s->execute([$id]);$questions=array_map(fn($x)=>['id'=>$x['id'],'question'=>$x['question'],'options'=>[$x['option_a'],$x['option_b'],$x['option_c'],$x['option_d']]],$s->fetchAll());out(['ok'=>true,'quiz'=>['id'=>$q['id'],'duration'=>$q['duration'],'negative'=>$q['negative_penalty']],'questions'=>$questions]);
 }
 if($a==='submit_quiz'){
  role($u,['student']);$id=(int)($b['id']??0);$answers=$b['answers']??[];$s=db()->prepare("SELECT * FROM quizzes WHERE id=? AND status='published'");$s->execute([$id]);$quiz=$s->fetch();if(!$quiz)out(['ok'=>false,'error'=>'Quiz unavailable.'],404);
  $s=db()->prepare('SELECT id,correct_option FROM quiz_questions WHERE quiz_id=?');$s->execute([$id]);$questions=$s->fetchAll();$correct=0;$wrong=0;$detail=[];
  foreach($questions as $i=>$q){$ans=$answers[(string)$i]??$answers[$i]??null;if($ans===null)continue;if((int)$ans===(int)$q['correct_option'])$correct++;else $wrong++;}
  $result=run_engine(count($questions),$correct,$wrong,(float)$quiz['negative_penalty']);
  $s=db()->prepare('INSERT INTO quiz_attempts(quiz_id,student_id,score,total,correct_count,wrong_count,accuracy) VALUES(?,?,?,?,?,?,?)');$s->execute([$id,$uid,$result['score'],$result['total'],$correct,$wrong,$result['accuracy']]);log_event('Submitted quiz ID '.$id);out(['ok'=>true]+$result);
 }
 if($a==='quiz_results'){
  role($u,['teacher','admin']);$id=(int)($b['id']??0);$sql=$r==='admin'?'SELECT a.*,u.name student_name,u.email student_email FROM quiz_attempts a JOIN users u ON u.id=a.student_id WHERE a.quiz_id=?':'SELECT a.*,u.name student_name,u.email student_email FROM quiz_attempts a JOIN users u ON u.id=a.student_id JOIN quizzes q ON q.id=a.quiz_id WHERE a.quiz_id=? AND q.teacher_id=?';$s=db()->prepare($sql);$s->execute($r==='admin'?[$id]:[$id,$uid]);out(['ok'=>true,'items'=>$s->fetchAll()]);
 }
 if($a==='create_resource'){role($u,['teacher','admin']);$url=trim((string)($b['url']??''));if($url!==''&&!filter_var($url,FILTER_VALIDATE_URL))out(['ok'=>false,'error'=>'Enter a valid resource URL.'],422);$s=db()->prepare('INSERT INTO resources(teacher_id,title,kind,description,url) VALUES(?,?,?,?,?)');$s->execute([$uid,trim((string)$b['title']),substr((string)($b['kind']??'Resource'),0,40),substr((string)($b['description']??''),0,2000),$url]);log_event('Published resource');out(['ok'=>true]);}
 if($a==='connect_student'){role($u,['teacher']);$email=strtolower(trim((string)($b['email']??'')));$s=db()->prepare("SELECT id FROM users WHERE email=? AND role='student' AND status='active'");$s->execute([$email]);$sid=$s->fetchColumn();if(!$sid)out(['ok'=>false,'error'=>'Active student account not found.'],404);db()->prepare('INSERT INTO teacher_students(teacher_id,student_id,status) VALUES(?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status)')->execute([$uid,$sid,'active']);out(['ok'=>true]);}
 if($a==='connect_teacher'){role($u,['student']);$tid=(int)($b['teacher_id']??0);$s=db()->prepare("SELECT id FROM users WHERE id=? AND role='teacher' AND status='active'");$s->execute([$tid]);if(!$s->fetchColumn())out(['ok'=>false,'error'=>'Teacher not found.'],404);db()->prepare('INSERT IGNORE INTO teacher_students(teacher_id,student_id,status) VALUES(?,?,?)')->execute([$tid,$uid,'pending']);out(['ok'=>true]);}
 if($a==='set_connection_status'){role($u,['teacher']);$id=(int)($b['id']??0);$status=(string)($b['status']??'');if(!in_array($status,['active','rejected'],true))out(['ok'=>false,'error'=>'Invalid connection status.'],422);$s=db()->prepare('UPDATE teacher_students SET status=? WHERE id=? AND teacher_id=?');$s->execute([$status,$id,$uid]);out(['ok'=>true]);}
 if($a==='set_user_status'){role($u,['admin']);$id=(int)($b['id']??0);$status=(string)($b['status']??'');if(!in_array($status,['active','blocked','pending'],true)||$id===$uid)out(['ok'=>false,'error'=>'Invalid account action.'],422);db()->prepare('UPDATE users SET status=? WHERE id=?')->execute([$status,$id]);log_event('Changed user '.$id.' status to '.$status);out(['ok'=>true]);}
 if($a==='create_ticket'){role($u,['student']);$subject=trim((string)($b['subject']??''));$msg=trim((string)($b['message']??''));if($subject===''||$msg==='')out(['ok'=>false,'error'=>'Subject and message are required.'],422);db()->prepare('INSERT INTO support_tickets(student_id,category,subject,message) VALUES(?,?,?,?)')->execute([$uid,substr((string)$b['category'],0,60),substr($subject,0,150),substr($msg,0,4000)]);out(['ok'=>true]);}
 if($a==='reply_ticket'){role($u,['teacher','admin']);$id=(int)($b['id']??0);$reply=trim((string)($b['reply']??''));if($reply==='')out(['ok'=>false,'error'=>'Response is required.'],422);db()->prepare("UPDATE support_tickets SET reply=?,status='closed',responder_id=? WHERE id=?")->execute([substr($reply,0,4000),$uid,$id]);log_event('Replied to support ticket '.$id);out(['ok'=>true]);}
 if($a==='mentor_reply'){role($u,['student']);$m=mb_strtolower(trim((string)($b['message']??'')));$reply="I can help you break this topic into smaller steps. First, identify the exact concept and write what you already understand. Then compare it with your lecture notes and try one example. For verified subject-specific guidance, submit this as a doubt so your faculty can respond. This built-in mentor is an offline guidance helper, not a connected generative-AI service.";if(str_contains($m,'quiz')||str_contains($m,'exam'))$reply="For quiz preparation: revise one topic at a time, practice a few questions without notes, then review every incorrect answer. Use the Flashcards section for quick recall. Ask your faculty if an answer depends on your course syllabus.";if(str_contains($m,'hello')||str_contains($m,'hi'))$reply="Hello! Tell me your subject and the concept you are studying. I can suggest a structured way to approach it, and you can raise a faculty doubt for a verified explanation.";out(['ok'=>true,'reply'=>$reply]);}
 if($a==='save_flashcard'){role($u,['student']);db()->prepare('INSERT INTO flashcards(user_id,front,back) VALUES(?,?,?)')->execute([$uid,substr((string)$b['front'],0,500),substr((string)$b['back'],0,2000)]);out(['ok'=>true]);}
 if($a==='flashcards'){role($u,['student']);$s=db()->prepare('SELECT front,back FROM flashcards WHERE user_id=? ORDER BY id DESC');$s->execute([$uid]);out(['ok'=>true,'items'=>$s->fetchAll()]);}
 out(['ok'=>false,'error'=>'Unknown action.'],400);
} catch(Throwable $e){if(db()->inTransaction())db()->rollBack();error_log('EduNova API: '.$e->getMessage());out(['ok'=>false,'error'=>'A server error occurred. Check the PHP error log and setup instructions.'],500);}

