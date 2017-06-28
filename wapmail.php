<?php

//Session lifetime.
$session_lifetime = 900;

//Default imap/pop3 host.
$host_default = "";

//if value is set to true, user can't change host.
$host_fixed = false;

//Defaul protocol.
$protocol_default = "imaps";

//if value is set to true, user can't change protocol.
$protocol_fixed = false;

//Truncate messages greater then XX characters.
$message_maxlen = 2048;

//Count of messages per one page.
$messages_perpage = 10;

//Specify a Trash folder here if you want a less-permanent delete.
$folder_trash = "";

//Suffixes for connection strings
$cs_suffixes["imap"]  = ":143/imap";
$cs_suffixes["imaps"] = ":993/imap/ssl/novalidate-cert";
$cs_suffixes["pop3"]  = ":110/pop3";
$cs_suffixes["pop3s"] = ":995/pop3/ssl/novalidate-cert";

//----------------------------------------------------------
//--------------- do not change below this line ------------
//----------------------------------------------------------

$cs_names["imap"]  = "imap";
$cs_names["imaps"] = "imaps";
$cs_names["pop3"]  = "pop3";
$cs_names["pop3s"] = "pop3s";

ini_set('session.gc_maxlifetime', $session_lifetime);
ini_set('session.cookie_lifetime', $session_lifetime);
ini_set('session.cache_expire', $session_lifetime);

session_name('WAPMAIL');
session_start();
setcookie(session_name(), session_id(), time() + $session_lifetime, '/');

if (!$_REQUEST['action']) {
    $_SESSION = array();
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 86400, '/');
    }
    session_destroy();
}

header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Content-Type: text/vnd.wap.wml; charset=utf-8");

print '<?xml version="1.0" encoding="utf-8"?>';
print '<!DOCTYPE wml PUBLIC "-//WAPFORUM//DTD WML 1.1//EN" "http://www.wapforum.org/DTD/wml_1.1.xml">';
print '<wml>';

$action = $_REQUEST["action"];
if ($_REQUEST['action'] && $_REQUEST['action'] != "login") {
    if (session_is_registered("connect")) {
        if (!empty($_REQUEST["folder"]))
            $_SESSION["folder"] = $_REQUEST["folder"];
        $box = @imap_open($_SESSION["connect"] . $_SESSION["folder"], $_SESSION["login"], $_SESSION["password"]);
        print '<template><do type="prev" label="LOGOUT"><go href="' . $_SERVER["PHP_SELF"] . '"/></do></template>';
    } else {
        $action = "error";
        $error  = "Session invalid.";
    }
}

if (!function_exists("imap_open")) {
    $action = "error";
    $error  = "Imap module is not installed.";
}

if (!function_exists("iconv")) {
    $action = "error";
    $error  = "Iconv module is not installed.";
}

$continue = true;
while ($continue) {
    $continue = false;
    switch ($action) {
        
        //SERVERANMELDUNG----------------------------------------------------
        case "login":
            $login    = $_REQUEST["login"];
            $password = $_REQUEST["password"];
            $host     = $_REQUEST["host"];
            $protocol = $_REQUEST["protocol"];
            if ($host_fixed)
                $host = $host_default;
            if ($protocol_fixed)
                $protocol = $protocol_default;
            if (empty($host)) {
                unset($action);
                $continue = true;
                break;
            }
            $connect = "{" . $host . $cs_suffixes[$protocol] . "}";
            if ($box = @imap_open($connect, $login, $password)) {
                
                $_SESSION["connect"]  = $connect;
                $_SESSION["host"]     = $host;
                $_SESSION["login"]    = $login;
                $_SESSION["password"] = $password;
                $_SESSION["protocol"] = $protocol;
                $_SESSION["folder"]   = "INBOX";
                
                $continue = true;
                $action   = "folders";
                if ($_SESSION["protocol"] == "pop3" || $_SESSION["protocol"] == "pop3s") {
                    $action = "list";
                }
            } else {
                $action   = "error";
                $continue = true;
                $error    = imap_last_error();
            }
            break;
        //IMAP-VERZEICHNISLISTE----------------------------------------------------
        case "folders":
            $folders = imap_getmailboxes($box, $_SESSION["connect"], "*");
            if (!count($folders)) {
                print '<card id="' . $action . '" ontimer="' . $_SERVER["PHP_SELF"] . '"><timer value="20"/><p>no folders</p></card>';
                break;
            }
            print '<card id="' . $action . '" title="IMAP MAILBOX"><p>';
            
            foreach ($folders as $afolder) {
                $folder = ereg_replace("\{.*\}", "", $afolder->name);
                $status = imap_status($box, $_SESSION["connect"] . $folder, SA_ALL);
                print (($status->unseen > 0) ? '<b>' : '') . '<a href="' . $_SERVER["PHP_SELF"] . '?action=list&amp;folder=' . urlencode($folder) . '&amp;pagenum=0">' . wmlspecialchars(mb_convert_encoding($folder, "UTF-8", "UTF7-IMAP")) . (($status->unseen > 0) ? ' (' . $status->unseen . ')' : '') . '</a>' . (($status->unseen > 0) ? '</b>' : '') . '<br/>';
            }
            print '</p>';
            print '<p><do type="accept" label="NEW MESSAGE"><go href="' . $_SERVER["PHP_SELF"] . '?action=write"/></do></p>';
            print '<p><do type="prev" label="LOGOUT"><go href="' . $_SERVER["PHP_SELF"] . '"/></do></p>';
            print '</card>';
            break;
        //MAILLISTE----------------------------------------------------
        case "list":
            $list = imap_sort($box, SORTARRIVAL, true, SE_UID || SE_NOPREFETCH);
            print '<card id="' . $action . '" title="' . wmlspecialchars(mb_convert_encoding($_SESSION["folder"], "UTF-8", "UTF7-IMAP")) . '">';
            if (count($list)) {
                $pagenum = intval((isset($_REQUEST["pagenum"])) ? $_REQUEST["pagenum"] : $_SESSION['pagenum']);
                $pages   = ceil(count($list) / $messages_perpage);
                $skip    = $pagenum * $messages_perpage;
                
                $_SESSION['pagenum'] = $pagenum;
                
                reset($list);
                if (count($list) > $skip) {
                    for ($i = 0; $i < $skip; $i++)
                        next($list);
                }
                $i = 0;
                while ((list($k, $msg) = each($list)) && ($i++ < $messages_perpage)) {
                    $headers    = headerinfo(imap_fetchheader($box, $msg, FT_UID));
                    $headerinfo = imap_headerinfo($box, imap_msgno($box, $msg));
                    $mark       = ($headerinfo->Unseen == "U" || $headerinfo->Recent == "N");
                    
                    print '<p>';
                    print ($mark ? '<b>* ' : '') . '[' . date("d.m.Y H:i", strtotime($headers["date"])) . ']<br/>';
                    
                    print '<a href="' . $_SERVER["PHP_SELF"] . '?action=view&amp;msg=' . $msg . '">';
                    
                    $adr  = imap_rfc822_parse_adrlist(str_replace(',', '', $headers["from"]), $_SESSION["host"]);
                    $from = $adr[0]->mailbox . '@' . $adr[0]->host;
                    print wmlspecialchars($from);
                    
                    print ': ';
                    
                    print wmlspecialchars(($headers["subject"]) ? $headers["subject"] : '(no subject)') . '</a>' . ($mark ? '</b>' : '');
                    print '</p>';
                }
                
                print '<p>';
                if ($pagenum > 0) {
                    print ' <a href="' . $_SERVER["PHP_SELF"] . '?action=' . $action . '&amp;pagenum=' . ($pagenum - 1) . '"> &lt; </a> ';
                }
                if ($pages > 1) {
                    print ' Page ' . ($pagenum + 1) . '/' . $pages . ' ';
                }
                if ($pagenum < $pages - 1) {
                    print ' <a href="' . $_SERVER["PHP_SELF"] . '?action=' . $action . '&amp;pagenum=' . ($pagenum + 1) . '"> &gt; </a>';
                }
                print '</p>';
            } else
                print '<p>empty folder.</p>';
            
            print '<p>';
            if ($_SESSION["protocol"] == "pop3" || $_SESSION["protocol"] == "pop3s") {
                print '<do type="accept" label="NEW MESSAGE"><go href="' . $_SERVER["PHP_SELF"] . '?action=write"/></do>';
            } else {
                print '<do type="prev" label="&lt; MAILBOX"><go href="' . $_SERVER["PHP_SELF"] . '?action=folders"/></do>';
            }
            print '</p>';
            print '</card>';
            break;
        //MAIL ANSEHEN----------------------------------------------------
        case "view":
            $msg     = $_REQUEST["msg"];
            $headers = headerinfo(imap_fetchheader($box, $msg, FT_UID));
            $body    = imap_body($box, $msg, FT_UID);
            print '<card id="' . $action . '" title="' . wmlspecialchars($headers["subject"]) . '"><p>';
            
            if ($mailoutput = get_part($box, $msg, "TEXT/PLAIN"))
                echo $mailoutput;
            elseif ($mailoutput = get_part($box, $msg, "TEXT/HTML"))
                echo '(HTML) ' . $mailoutput;
            else
                echo "no text in this message.";
            
            print '</p>';
            print '<p>';
            print '<a href="' . $_SERVER["PHP_SELF"] . '?action=reply&amp;msg=' . $msg . '">[reply]</a> ';
            print '<a href="' . $_SERVER["PHP_SELF"] . '?action=delete&amp;msg=' . $msg . '">[delete]</a>';
            print '</p>';
            print '<p>';
            print '<do type="prev" label="&lt; ' . wmlspecialchars(strtoupper(mb_convert_encoding($_SESSION["folder"], "UTF-8", "UTF7-IMAP"))) . '"><go href="' . $_SERVER["PHP_SELF"] . '?action=list"/></do>';
            print '</p>';
            print '</card>';
            break;
        //NACHRICHT LÖSCHEN----------------------------------------------------
        case "delete":
            $moved = false;
            if ($_SESSION["protocol"] == "imap" && !empty($folder_trash) && $_SESSION["folder"] != $folder_trash) {
                $moved   = imap_mail_move($box, $_REQUEST["msg"], $folder_trash, CP_UID);
                $message = "Message moved to " . $folder_trash;
            }
            if (!$moved) {
                imap_delete($box, $_REQUEST["msg"], FT_UID);
                $message = "Message deleted.";
            }
            imap_expunge($box);
            print '<card id="' . $action . '" ontimer="#list">';
            print '<timer value="5"/><p>' . $message . '</p></card>';
            $action   = "list";
            $continue = true;
            break;
        //NACHRICHT AN SERVER SCHICKEN----------------------------------------------------
        case "post":
            if (strlen($_REQUEST["from"]) > 7 && strlen($_REQUEST["to"]) > 7) {
                $hdrs = "MIME-Version: 1.0\n";
                $hdrs .= "Content-Type: text/plain; charset=utf-8\n";
                $hdrs .= "Content-Transfer-Encoding: 8bit\n";
                $hdrs .= "From: " . $_REQUEST["from"];
                
                $_SESSION["from"] = $_REQUEST["from"];
                
                imap_mail($_REQUEST["to"], $_REQUEST["subj"], preg_replace("/\s\s/is", "\r\n", $_REQUEST["body"]), $hdrs);
                
                print '<card id="' . $action . '" ontimer="' . $_SERVER["PHP_SELF"] . '?action=folders">';
                print '<timer value="20"/><p>posted to "' . wmlspecialchars($_REQUEST["to"]) . '".</p></card>';
            } else { //ungültige eingabe - formular nochmal vorlegen
                $from = $_REQUEST["from"];
                $to   = $_REQUEST["to"];
                $subj = $_REQUEST["subj"];
                $body = $_REQUEST["body"];
                print '<card id="' . $action . '" ontimer="#write">';
                print '<timer value="10"/><p>adress is missing.</p></card>';
                $action   = "write";
                $continue = true;
            }
            break;
        //NEUE NACHRICHT ERSTELLEN----------------------------------------------------
        case "write":
            if (!$_SESSION["from"]) {
                $from = $_SESSION["login"];
                if (strpos($from, "@") === false)
                    $from .= "@" . $_SESSION["host"];
            }
            print '<card id="' . $action . '" title="WRITE MAIL"><p>';
            print 'from:<br/><input type="text" name="from" title="from" maxlength="255" format="*m" emptyok="false" value="' . wmlspecialchars((($from) ? $from : $_SESSION["$from"])) . '"/><br/>';
            print 'to:<br/><input type="text" name="to" title="to" maxlength="255" format="*m" emptyok="false" value="' . wmlspecialchars($to) . '"/><br/>';
            print 'subject:<br/><input type="text" name="subj" title="subj" maxlength="255" value="' . wmlspecialchars($subj) . '"/><br/>';
            print 'body:<br/><input type="text" name="body" title="body" maxlength="2048" value="' . wmlspecialchars($body) . '"/><br/>';
            print '<do type="accept" label="SEND">';
            print '<go method="post" href="' . $_SERVER["PHP_SELF"] . '?action=post">';
            print '<postfield name="from" value="$(from)"/>';
            print '<postfield name="to" value="$(to)"/>';
            print '<postfield name="subj" value="$(subj)"/>';
            print '<postfield name="body" value="$(body)"/>';
            print '</go></do></p>';
            print '<p><do type="prev" label="&lt; MAILBOX"><go href="' . $_SERVER["PHP_SELF"] . '?action=folders"/></do></p>';
            print '</card>';
            break;
        //ANTWORTEN----------------------------------------------------
        case "reply":
            $headers = headerinfo(imap_fetchheader($box, $_REQUEST["msg"], FT_UID));
            
            $to = $headers["reply-to"];
            if (empty($to))
                $to = $headers["from"];
            
            $adr = imap_rfc822_parse_adrlist($to, $_SESSION["host"]);
            $to  = $adr['0']->mailbox . '@' . $adr['0']->host;
            
            $subj = $headers["subject"];
            if (ereg("Re: (.*)", $subj, $regs)) {
                $subj = "Re: " . $regs[1];
            } else {
                $subj = "Re: $subj";
            }
            $action   = "write";
            $continue = true;
            break;
        //FEHLER----------------------------------------------------
        case "error":
            print '<card id="' . $action . '" title="ERROR">';
            print '<onevent type="ontimer"><go href="' . $_SERVER["PHP_SELF"] . '"/></onevent><timer value="20"/>';
            print '<p>' . $error . '</p>';
            print '</card>';
            break;
        //AN-ABMELDUNG----------------------------------------------------
        default:
            print '<card id="welcome" title="WapMail">';
            print '<p>username:<br/><input type="text" name="login" title="login" emptyok="false" value="' . $_REQUEST['u'] . '"/><br/>';
            print 'password:<br/><input type="password" name="password" title="password" value="' . $_REQUEST['p'] . '"/><br/>';
            if (!$host_fixed) {
                echo 'host:<br/><input type="text" name="host" title="host" emptyok="false" value="' . (($_REQUEST['h']) ? $_REQUEST['h'] : $host_default) . '"/><br/>';
            }
            if (!$protocol_fixed) {
                print 'service:<br/><select name="protocol" title="protocol" value="' . (($_REQUEST['s']) ? $_REQUEST['s'] : $protocol_default) . '">';
                reset($cs_suffixes);
                while (list($k, $suffix) = each($cs_suffixes)) {
                    print '<option value="' . $k . '">' . $cs_names[$k] . '</option>';
                }
                print '</select><br/>';
            }
            print '<do type="accept" label="LOGIN">';
            print '<go method="post" href="' . $_SERVER["PHP_SELF"] . '?action=login">';
            print '<postfield name="login" value="$(login)"/>';
            print '<postfield name="password" value="$(password)"/>';
            print '<postfield name="host" value="$(host)"/>';
            print '<postfield name="protocol" value="$(protocol)"/>';
            print '</go></do></p>';
            print '</card>';
            break;
    }
}

if (isset($box) && function_exists("imap_close")) {
    @imap_close($box);
}

print '</wml>';

//===========================================================================================================================
//===========================================================================================================================

function headerinfo($header) {
    $header = str_replace("\t", " ", $header);
    $lines  = explode("\n", $header);
    
    $name = "x-xxx";
    reset($lines);
    while (list($i, $header) = each($lines)) {
        if ($header[0] != " ") {
            list($name, $value) = explode(": ", $header, 2);
            $name           = strtolower($name);
            $headers[$name] = trim($value);
        } else {
            $headers[$name] .= " " . trim($header);
        }
    }
    
    $charset = "us-ascii";
    $cta     = explode(";", $headers["content-type"]);
    while (list($i, $ct) = each($cta)) {
        list($name, $value) = explode("=", $ct, 2);
        $name  = strtolower(trim($name));
        $value = strtolower(trim($value));
        $value = str_replace("'", "", str_replace('"', '', $value));
        if ($name == "charset")
            $charset = $value;
    }
    
    reset($headers);
    while (list($name, $value) = each($headers)) {
        $elements = imap_mime_header_decode($value);
        while (list($e, $element) = each($elements)) {
            if ($element->charset == "default") {
                $info[$name] .= xiconv($charset, "utf-8", $element->text);
            } else {
                $info[$name] .= xiconv($element->charset, "utf-8", $element->text);
            }
        }
    }
    $info["charset"] = $charset;
    return $info;
}

//----------------------------------------------------
function xtrim($text) {
    $text = trim($text);
    $text = str_replace("\r\n", "\n", $text);
    $text = preg_replace("/\n[\s\n]+/is", "<br/><br/>", $text);
    $text = str_replace("\n", "<br/>", $text);
    $text = preg_replace("/\s+/is", " ", $text);
    $text = trim($text);
    return $text;
}

//----------------------------------------------------
function wmlspecialchars($text) {
    $text = str_replace(array(
        "'",
        "&",
        '"',
        "<",
        ">",
        "$"
    ), array(
        "&apos;",
        "&amp;",
        "&quot;",
        "&lt;",
        "&gt;",
        "$$"
    ), $text);
    return $text;
}

//----------------------------------------------------
function xiconv($frpm, $to, $text) {
    $text = iconv($frpm, $to, $text);
    if (($pos = strpos($text, chr(0))) !== false)
        $text = substr($text, 0, $pos);
    return $text;
}

//----------------------------------------------------
function cmp($a, $b) {
    return (strtoupper($a->name) < strtoupper($b->name)) ? -1 : 1;
}

//----------------------------------------------------
function get_mime_type(&$structure) {
    $primary_mime_type = array(
        "text",
        "multipart",
        "message",
        "application",
        "audio",
        "image",
        "video",
        "other"
    );
    if ($structure->subtype) {
        return $primary_mime_type[(int) $structure->type] . '/' . $structure->subtype;
    }
    return "text/plain";
}
//----------------------------------------------------
function get_part($stream, $msg_number, $mime_type, $structure = false, $part_number = false) {
    
    if (!$structure) {
        $structure = imap_fetchstructure($stream, $msg_number, FT_UID);
    }
    if ($structure) {
        if ($mime_type == get_mime_type($structure)) {
            if (!$part_number) {
                $part_number = 1;
            }
            
            global $message_maxlen;
            //ausgabeformatierung
            $text = imap_fetchbody($stream, $msg_number, $part_number, FT_UID);
            if ($structure->encoding == 3) {
                $text = imap_base64($text);
            } else if ($structure->encoding == 4) {
                $text = imap_qprint($text);
            }
            // var_dump($structure->parameters);
            if (is_array($structure->parameters)) {
                foreach ($structure->parameters as $param) {
                    if ($param->attribute == "charset") {
                        $charset = $param->value;
                    }
                }
            } else {
                $charset = "iso-8859-1";
            }
            $text = strip_tags($text);
            if (strlen($text) > $message_maxlen)
                $text = substr($text, 0, $message_maxlen);
            $text = xtrim(wmlspecialchars($text));
            if (!empty($charset) && $charset != "utf-8")
                $text = xiconv($charset, "utf-8", $text);
            return $text;
        }
        if ($structure->type == 1) /* multipart */ {
            while (list($index, $sub_structure) = each($structure->parts)) {
                if ($part_number) {
                    $prefix = $part_number . '.';
                }
                $data = get_part($stream, $msg_number, $mime_type, $sub_structure, $prefix . ($index + 1));
                if ($data) {
                    return $data;
                }
            } // END OF WHILE
        } // END OF MULTIPART
    } // END OF STRUTURE
    return false;
} // END OF FUNCTION

?>