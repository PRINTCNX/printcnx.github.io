<?php
/**
 * TPC Website — Universal Form Handler v2
 * Handles: quote, order, upload, contact
 * Deploy to: /forms/handler.php on cPanel
 */

// ── Config ───────────────────────────────────────────────────────────────────
// Primary recipient gets the email first; all others are BCC'd as redundancy.
// These addresses are server-side only — never exposed in page HTML.
$STAFF = ['customerservice@printcnx.com', 'prepress@printcnx.com'];
$FROM_NAME  = 'The Printing Connection Website';
$FROM_EMAIL = 'noreply@printcnx.com';
$REPLY_TO   = 'prepress@printcnx.com';
$UPLOAD_DIR = __DIR__ . '/uploads/';
$MAX_MB     = 1024;  // 1 GB — matches JotForm paid plan max
$LOG_FILE   = __DIR__ . '/submissions.log';
$ALLOWED    = ['pdf','ai','eps','psd','tif','tiff','indd','idml',
               'jpg','jpeg','png','gif','zip','rar','7z','svg','doc','docx'];

// ── Helpers ──────────────────────────────────────────────────────────────────
function s(string $v): string {
    return htmlspecialchars(strip_tags(trim($v)), ENT_QUOTES, 'UTF-8');
}
function f(string $k, string $d = ''): string { return s($_POST[$k] ?? $d); }

function out(bool $ok, string $msg): void {
    header('Content-Type: application/json');
    http_response_code($ok ? 200 : 422);
    echo json_encode(['ok'=>$ok,'message'=>$msg]);
    exit;
}

function hdrs(string $from, string $fe, string $rt, array $cc=[]): string {
    $h  = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
    $h .= "From: {$from} <{$fe}>\r\nReply-To: {$rt}\r\n";
    if ($cc) $h .= 'Cc: '.implode(', ',$cc)."\r\n";
    return $h;
}

function shell(string $title, string $body, string $accent='#1e7abf'): string {
    return <<<H
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>{$title}</title></head>
<body style="margin:0;padding:0;background:#f4f7fb;font-family:'Segoe UI',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f7fb;padding:32px 16px;">
<tr><td align="center"><table width="600" cellpadding="0" cellspacing="0"
  style="max-width:600px;background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 4px 32px rgba(0,0,0,.09);">
<tr><td style="background:linear-gradient(135deg,#071828 0%,{$accent} 100%);padding:26px 32px;">
  <img src="https://www.printcnx.com/assets/images/logo-horizontal-white.svg"
       alt="The Printing Connection" height="44" style="display:block;max-width:220px;">
</td></tr>
<tr><td style="padding:32px;">{$body}</td></tr>
<tr><td style="background:#f8fafc;border-top:1px solid #e8edf2;padding:18px 32px;text-align:center;">
  <p style="margin:0;font-size:11px;color:#9ca3af;">
    The Printing Connection · 3533 Old Conejo Rd. #104 · Newbury Park, CA 91320<br>
    <a href="tel:8007865490" style="color:#1e7abf;">800-786-5490</a> &nbsp;·&nbsp;
    <a href="mailto:info@printcnx.com" style="color:#1e7abf;">info@printcnx.com</a>
  </p>
</td></tr>
</table></td></tr></table></body></html>
H;
}

function row(string $l, string $v): string {
    if (trim($v)==='') return '';
    return "<tr>
      <td style='padding:7px 12px;background:#f8fafc;font-size:11px;font-weight:700;color:#6b7280;
          text-transform:uppercase;letter-spacing:.06em;width:150px;vertical-align:top;
          border-bottom:1px solid #e8edf2;white-space:nowrap;'>{$l}</td>
      <td style='padding:7px 12px;font-size:13px;color:#1a2744;border-bottom:1px solid #e8edf2;
          vertical-align:top;'>{$v}</td></tr>";
}
function tbl(string $rows): string {
    return "<table width='100%' cellpadding='0' cellspacing='0'
      style='border:1px solid #e8edf2;border-radius:8px;overflow:hidden;
             border-collapse:collapse;margin:14px 0;'><tbody>{$rows}</tbody></table>";
}
function reply_btn(string $email, string $name): string {
    return "<p style='margin:24px 0 0;'>
      <a href='mailto:{$email}' style='background:#1e7abf;color:#fff;padding:10px 22px;
         border-radius:6px;text-decoration:none;font-weight:700;font-size:13px;'>
         Reply to {$name} →</a></p>";
}
function note_box(string $text): string {
    return "<div style='background:#f8fafc;border:1px solid #e8edf2;border-radius:8px;
        padding:14px 16px;font-size:13px;color:#374151;line-height:1.75;margin:12px 0;'>"
        .nl2br($text)."</div>";
}
function ph_link(string $phone): string {
    return "<a href='tel:".preg_replace('/\D/','',$phone)."' style='color:#1e7abf;'>{$phone}</a>";
}

// ── Bot + method guards ───────────────────────────────────────────────────────
if (!empty($_POST['_hp'])) out(false,'');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(false,'Method not allowed.');

// ── File uploads (shared) ─────────────────────────────────────────────────────
$uploaded = []; $upErr = [];
$fileInput = !empty($_FILES['files']) ? $_FILES['files'] : (!empty($_FILES['file']) ? $_FILES['file'] : null);
if ($fileInput && !empty($fileInput['name'][0])) {
    // Normalise single → array
    if (!is_array($fileInput['name'])) {
        foreach (['name','tmp_name','size','error'] as $k) $fileInput[$k] = [$fileInput[$k]];
    }
    if (!is_dir($UPLOAD_DIR)) mkdir($UPLOAD_DIR, 0750, true);
    $dir = $UPLOAD_DIR.date('Y-m').'/';
    if (!is_dir($dir)) mkdir($dir, 0750, true);

    foreach ($fileInput['name'] as $i => $orig) {
        if ($fileInput['error'][$i] !== UPLOAD_ERR_OK || !$orig) continue;
        if ($fileInput['size'][$i] > $MAX_MB*1024*1024) { $upErr[] = "{$orig}: exceeds {$MAX_MB}MB"; continue; }
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!in_array($ext,$ALLOWED,true)) { $upErr[] = "{$orig}: .{$ext} not allowed"; continue; }
        $safe = date('Ymd_His').'_'.preg_replace('/[^a-zA-Z0-9._-]/','_',$orig);
        if (move_uploaded_file($fileInput['tmp_name'][$i], $dir.$safe)) {
            $uploaded[] = ['orig'=>$orig,'size'=>round($fileInput['size'][$i]/1024,1).' KB'];
        }
    }
}
$fileHtml = '';
if ($uploaded) {
    $rows = '';
    foreach ($uploaded as $f2) $rows .= row($f2['size'], '📎 '.$f2['orig']);
    $fileHtml = "<h4 style='color:#071828;margin:18px 0 6px;font-size:13px;'>Uploaded Files (".count($uploaded).")</h4>".tbl($rows);
}
if ($upErr) $fileHtml .= "<p style='color:#c53030;font-size:12px;margin:6px 0;'>".implode('<br>',$upErr)."</p>";

// ── Route ─────────────────────────────────────────────────────────────────────
$type = f('form_type','contact');

// ─────────────────── QUOTE ───────────────────────────────────────────────────
if ($type === 'quote') {
    $name    = f('name');    $email   = f('email');
    $phone   = f('phone');   $company = f('company');
    $service = f('service'); $qty     = f('quantity');
    $deadline= f('deadline');$details = f('details');
    $source  = f('source');

    if (!$name||!$email||!filter_var($email,FILTER_VALIDATE_EMAIL)||!$service||!$details)
        out(false,'Please fill in all required fields (Name, Email, Service, Description).');

    $subj = "Quote Request — {$service} — {$name}".($company?" ({$company})":'');
    $staffBody =
        "<h2 style='color:#071828;font-size:19px;margin:0 0 4px;'>New Quote Request</h2>
         <p style='color:#9ca3af;font-size:12px;margin:0 0 18px;'>".date('F j, Y \a\t g:i A T')."</p>".
        tbl(row('Name',$name).row('Company',$company)
            .row('Email',"<a href='mailto:{$email}' style='color:#1e7abf;'>{$email}</a>")
            .row('Phone',$phone?ph_link($phone):'')
            .row('Service',$service).row('Quantity',$qty).row('Deadline',$deadline)
            .row('Found us',$source)).
        "<h4 style='color:#071828;margin:14px 0 6px;font-size:13px;'>Project Description</h4>".
        note_box($details).$fileHtml.reply_btn($email,$name);

    $clientBody =
        "<h2 style='color:#071828;font-size:21px;margin:0 0 10px;'>Thanks, {$name}!</h2>
         <p style='color:#374151;line-height:1.75;'>We received your quote request for
         <strong>{$service}</strong> and will respond with a detailed, itemised quote within
         <strong>1 business day</strong>.</p>
         <p style='color:#374151;line-height:1.75;'>Need it faster? Call us:</p>
         <p><a href='tel:8007865490' style='background:#0bb283;color:#fff;padding:11px 22px;
            border-radius:6px;text-decoration:none;font-weight:700;display:inline-block;'>
            📞 800-786-5490</a></p>
         <p style='color:#9ca3af;font-size:12px;margin:20px 0 0;'>
            Summary: {$service} · Qty: {$qty}".($deadline?" · Needed by {$deadline}":'')."</p>";

    $pri = array_shift($STAFF);
    mail($pri, $subj, shell($subj,$staffBody), hdrs($FROM_NAME,$FROM_EMAIL,$email,$STAFF));
    mail($email,'Quote Request Received — The Printing Connection',
         shell('Quote Request Received',$clientBody,'#0bb283'), hdrs($FROM_NAME,$FROM_EMAIL,$REPLY_TO));
    file_put_contents($LOG_FILE,
        json_encode(['ts'=>date('c'),'type'=>'quote','name'=>$name,'email'=>$email,'service'=>$service,'files'=>count($uploaded)])."\n",
        FILE_APPEND|LOCK_EX);
    out(true,'Quote request received! Check your email for a confirmation.');
}

// ─────────────────── ORDER / REORDER ─────────────────────────────────────────
if ($type === 'order') {
    $name      = f('name');     $email    = f('email');
    $phone     = f('phone');    $company  = f('company');
    $orderType = f('order_type','New Order');
    $po        = f('po_number');$prevJob  = f('prev_job');
    $project   = f('project');  $qty      = f('quantity');
    $deadline  = f('deadline'); $artwork  = f('artwork_ready');
    $notes     = f('notes');

    if (!$name||!$email||!filter_var($email,FILTER_VALIDATE_EMAIL)||!$project)
        out(false,'Please provide your name, email, and project name.');

    $tag   = strtoupper($orderType==='Reorder'?'REORDER':'NEW ORDER');
    $subj  = "{$tag} — {$project} — {$name}".($company?" ({$company})":'');
    $staffBody =
        "<h2 style='color:#071828;font-size:19px;margin:0 0 4px;'>{$tag}: {$project}</h2>
         <p style='color:#9ca3af;font-size:12px;margin:0 0 18px;'>".date('F j, Y \a\t g:i A T')."</p>".
        tbl(row('Name',$name).row('Company',$company)
            .row('Email',"<a href='mailto:{$email}' style='color:#1e7abf;'>{$email}</a>")
            .row('Phone',$phone?ph_link($phone):'')
            .row('Order Type',$orderType).row('PO Number',$po)
            .row('Prev. Job #',$prevJob).row('Project',$project)
            .row('Quantity',$qty).row('Deadline',$deadline)
            .row('Artwork',$artwork)).
        ($notes ? note_box($notes) : '').
        $fileHtml.reply_btn($email,$name);

    $clientBody =
        "<h2 style='color:#071828;font-size:21px;margin:0 0 10px;'>Order Received, {$name}!</h2>
         <p style='color:#374151;line-height:1.75;'>We've received your
         <strong>{$orderType}</strong> for <strong>{$project}</strong>.
         Our team will review and follow up to confirm details and timeline.</p>
         <p><a href='tel:8007865490' style='background:#0bb283;color:#fff;padding:11px 22px;
            border-radius:6px;text-decoration:none;font-weight:700;display:inline-block;'>
            📞 800-786-5490</a></p>";

    $pri = array_shift($STAFF);
    mail($pri,$subj,shell($subj,$staffBody), hdrs($FROM_NAME,$FROM_EMAIL,$email,$STAFF));
    mail($email,'Order Received — The Printing Connection',
         shell('Order Received',$clientBody,'#0bb283'), hdrs($FROM_NAME,$FROM_EMAIL,$REPLY_TO));
    file_put_contents($LOG_FILE,
        json_encode(['ts'=>date('c'),'type'=>'order','name'=>$name,'email'=>$email,'project'=>$project,'orderType'=>$orderType,'files'=>count($uploaded)])."\n",
        FILE_APPEND|LOCK_EX);
    out(true,'Order submitted! You\'ll receive a confirmation email shortly.');
}

// ─────────────────── FILE UPLOAD ─────────────────────────────────────────────
if ($type === 'upload') {
    $name    = f('name');  $email   = f('email');
    $company = f('company'); $project = f('project');
    $job     = f('job_number'); $notes = f('notes');

    if (!$name||!$email||!filter_var($email,FILTER_VALIDATE_EMAIL))
        out(false,'Please provide your name and email.');

    $subj = "File Upload — {$project} — {$name}".($company?" ({$company})":'');
    $staffBody =
        "<h2 style='color:#071828;font-size:19px;margin:0 0 4px;'>File Upload</h2>
         <p style='color:#9ca3af;font-size:12px;margin:0 0 18px;'>".date('F j, Y \a\t g:i A T')."</p>".
        tbl(row('Name',$name).row('Company',$company)
            .row('Email',"<a href='mailto:{$email}' style='color:#1e7abf;'>{$email}</a>")
            .row('Phone',f('phone')?ph_link(f('phone')):'')
            .row('Project',$project).row('Job #',$job)).
        ($notes?note_box($notes):'').$fileHtml.reply_btn($email,$name);

    $clientBody =
        "<h2 style='color:#071828;font-size:21px;margin:0 0 10px;'>Files Received, {$name}!</h2>
         <p style='color:#374151;line-height:1.75;'>We received <strong>".count($uploaded)." file(s)</strong>
         for <strong>{$project}</strong>. Our prepress team will review them and reach out if
         we have any questions.</p>
         <p><a href='tel:8007865490' style='background:#0bb283;color:#fff;padding:11px 22px;
            border-radius:6px;text-decoration:none;font-weight:700;display:inline-block;'>
            📞 800-786-5490</a></p>";

    $pri = array_shift($STAFF);
    mail($pri,$subj,shell($subj,$staffBody), hdrs($FROM_NAME,$FROM_EMAIL,$email,$STAFF));
    mail($email,'Files Received — The Printing Connection',
         shell('Files Received',$clientBody,'#0bb283'), hdrs($FROM_NAME,$FROM_EMAIL,$REPLY_TO));
    file_put_contents($LOG_FILE,
        json_encode(['ts'=>date('c'),'type'=>'upload','name'=>$name,'email'=>$email,'project'=>$project,'files'=>count($uploaded)])."\n",
        FILE_APPEND|LOCK_EX);
    out(true,'Files uploaded successfully! You\'ll receive a confirmation email shortly.');
}

// ─────────────────── CONTACT ─────────────────────────────────────────────────
if ($type === 'contact') {
    $name    = f('name');    $email   = f('email');
    $phone   = f('phone');   $company = f('company');
    $subject = f('subject'); $message = f('message');

    if (!$name||!$email||!filter_var($email,FILTER_VALIDATE_EMAIL)||!$message)
        out(false,'Please fill in your name, email, and message.');

    $subj = "Contact: {$subject} — {$name}".($company?" ({$company})":'');
    $staffBody =
        "<h2 style='color:#071828;font-size:19px;margin:0 0 4px;'>New Contact Message</h2>
         <p style='color:#9ca3af;font-size:12px;margin:0 0 18px;'>".date('F j, Y \a\t g:i A T')."</p>".
        tbl(row('Name',$name).row('Company',$company)
            .row('Email',"<a href='mailto:{$email}' style='color:#1e7abf;'>{$email}</a>")
            .row('Phone',$phone?ph_link($phone):'').row('Subject',$subject)).
        note_box($message).reply_btn($email,$name);

    $first = explode(' ',$name)[0];
    $clientBody =
        "<h2 style='color:#071828;font-size:21px;margin:0 0 10px;'>Thanks, {$first}!</h2>
         <p style='color:#374151;line-height:1.75;'>We received your message and will get back to
         you within <strong>1 business day</strong>. For urgent matters:</p>
         <p><a href='tel:8007865490' style='background:#0bb283;color:#fff;padding:11px 22px;
            border-radius:6px;text-decoration:none;font-weight:700;display:inline-block;'>
            📞 800-786-5490</a></p>
         <p style='color:#9ca3af;font-size:12px;margin:20px 0 0;'>Your message: <em>"
         .htmlspecialchars(substr($message,0,200)).(strlen($message)>200?'…':'')."</em></p>";

    $pri = array_shift($STAFF);
    mail($pri,$subj,shell($subj,$staffBody), hdrs($FROM_NAME,$FROM_EMAIL,$email,$STAFF));
    mail($email,'Message Received — The Printing Connection',
         shell('Message Received',$clientBody,'#0bb283'), hdrs($FROM_NAME,$FROM_EMAIL,$REPLY_TO));
    file_put_contents($LOG_FILE,
        json_encode(['ts'=>date('c'),'type'=>'contact','name'=>$name,'email'=>$email,'subject'=>$subject])."\n",
        FILE_APPEND|LOCK_EX);
    out(true,'Message sent! We\'ll be in touch within 1 business day.');
}

out(false, 'Unknown form type.');
