<?php
// ini_set('display_errors','1');
define ("CONTENT_TYPE_TEXT_PLAIN", 1);
define ("CONTENT_TYPE_MULTIPART", 2);
define ("DEBUG_OFF", 1);
define ("DEBUG_NORMAL", 2);
define ("DEBUG_VERBOSE", 3);
define ("DEBUG_VERY_VERBOSE", 4);
define ("DEBUG_VERY_VERY_VERBOSE", 5);

$minorDomo = new MinorDomo();
$minorDomo->run();

class MinorDomo {
/////////////////////////////////////////////
//
// minorDomo - a simple replacement for majorDomo
// started out as majorDummy, but soon found out that a remailer has to have some
// intelligence around headers, can't just tack on a reply-to, you're only allowed one.
//
// majorDummy - a simple program to take mail sent to jssccBoard@santacruzjazz.org
// and remail it to the addresses found in ./boardmembers.txt
//

    protected $debug = DEBUG_OFF;
    protected $popSock = null;
    protected $smtpSock = null;
    protected $echo;
    protected $logfile;
	protected $lists;

    public function openLogFile() {        
        if ($this->debug != DEBUG_OFF) {
            if (false === ($this->logfile = fopen("./minorDomo.log", "a+"))) {
                print("Warning: unable to open log file, turning off debugging\n"); flush();
            	$this->debug = DEBUG_OFF;
                $this->logfile = null;
            }
        }
    }
    
    public function logit($level, $str) {
        $str = addcslashes($str, "\r\n");
        if (($this->debug >= $level) && ($this->logfile != null)) {
            $timeStamp = date("Y-m-d H:i:s  ");
            fwrite($this->logfile, $timeStamp . $str . "\n");
            fflush($this->logfile);
        }
        if ($this->echo) {
            echo $timeStamp . $str . "\n";
        }
    }
    
    public function getLists() {
        if ($handle = opendir("./lists")) {
            $list = array("popport" => "110", "smtpport" => "587");
            while (false !== ($fname = readdir($handle))) {
                $pos = strpos($fname, ".");
                if (($pos === false) || ($pos != 0)) {
                    $xml = file_get_contents("lists/".$fname);
                    $parser = xml_parser_create();
                    xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 0);
                    xml_parser_set_option($parser, XML_OPTION_SKIP_WHITE, 1);
                    xml_parse_into_struct($parser, $xml, $vals, $tags);
                    xml_parser_free($parser);
                    // print_r($tags);
                    foreach($tags as $key=>$val) {
                        if ($key == "name") {
                            $list['name'] = $vals[$val[0]]['value'];
                        } else if ($key == "mailhost") {
                            $list['mailhost'] = $vals[$val[0]]['value'];
                        } else if ($key == "user") {
                            $list['user'] = $vals[$val[0]]['value'];
                        } else if ($key == "pass") {
                            $list['pass'] = base64_decode($vals[$val[0]]['value']);
                        } else if ($key == "domain") {
                            $list['domain'] = $vals[$val[0]]['value'];
                        } else if ($key == "popport") {
                            $list['popport'] = $vals[$val[0]]['value'];
                        } else if ($key == "smtpport") {
                            $list['smtpport'] = $vals[$val[0]]['value'];
                        } else if ($key == "member") {
                            for ($i = 0; $i < count($val); $i++) {
                                $members[] = $vals[$val[$i]]['value'];
                            }
                        }
                    }
                    $list['members'] = $members;
                    unset($members);
					if (!isset($list['domain'])) {
						$list['domain'] = preg_replace("/.*@/", "", $list['user']);
					}
                    $this->lists[] = $list;
                }
			}
			closedir($handle);
        }
    }
    
    public function openPopSock($mailhost, $popport) {
        
        $this->logit(DEBUG_NORMAL, "opening pop socket $mailhost\n");
        if (false !== ($this->popSock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP))) {
            $inet4 = gethostbyname($mailhost);
            if ($popport == null) $popport = 110;
            if (socket_connect($this->popSock, $inet4, $popport)) {
                socket_set_block($this->popSock);
                $result = socket_read($this->popSock, 512, PHP_BINARY_READ);
                $this->logit (DEBUG_NORMAL, "$result");
                }
            }
    }
    
    public function login($user, $pass) {
            
        $cmd = "USER $user";
        $this->logit (DEBUG_NORMAL, "sending $cmd");
        socket_write($this->popSock, "$cmd\r\n");
        $result = socket_read($this->popSock, 512, PHP_BINARY_READ);
        $this->logit (DEBUG_NORMAL, "USER result: $result");
         
        $this->logit (DEBUG_NORMAL, "sending PASS");
        socket_write($this->popSock, "PASS $pass\r\n");
        $result = socket_read($this->popSock, 512, PHP_BINARY_READ);
        $this->logit (DEBUG_NORMAL, "PASS result: $result");
        if (false === (strpos($result, "+OK"))) {
            return false;
        } else {
            return true;
        }
    }
    
    public function getMsgCount() {
    
        $this->logit (DEBUG_NORMAL, "sending STAT");
        socket_write($this->popSock, "STAT\r\n");
        $result = socket_read($this->popSock, 512, PHP_BINARY_READ);
        $this->logit (DEBUG_NORMAL, "STAT result: $result");
    
        $parts = explode(" ", $result);
        $numMsgs = $parts[1];
        $this->logit (DEBUG_NORMAL, "$numMsgs messages");
        return $numMsgs;
    
    }
    
    public function readSock($pushBack, $needCRLF) {
        
        $this->logit (DEBUG_VERY_VERBOSE, "readSock: pushBack=$pushBack, needCRLF=$needCRLF");
    
        static $pushBackBuf;
        $buf = "";
        
        if ($pushBack != null) {
            $pushBackBuf = $pushBack . $pushBackBuf;
        } else if ($pushBackBuf != null) {
            $buf = $pushBackBuf;
            $pushBackBuf = null;
        } else {
            if ($needCRLF) {
                $CRLFFound = false;
                $attempts = 0;
                while (!$CRLFFound) {
                    if (false !== ($len = socket_recv($this->popSock, $peekBuf, 2048, MSG_PEEK))) {
                        $this->logit (DEBUG_VERY_VERY_VERBOSE, "readSock: looking for CRLF, peekBuf = $peekBuf");
                        if (false !== strpos($peekBuf, "\r\n")) {
                            $this->logit (DEBUG_VERY_VERY_VERBOSE, "readSock: found CRLF");
                            $CRLFFound = true;
                        } else while ($attempts++ < 12) {
                            $this->logit (DEBUG_VERY_VERBOSE, "readSock: sleeping");
                            sleep(1);
                        }
                    }
                }
            } else {
                $len = socket_recv($this->popSock, $peekBuf, 1024, MSG_PEEK);
            }
            if ($peekBuf == "\r\n.\r\n") {
                $len = socket_recv($this->popSock, $buf, 5, 0);
                $this->logit (DEBUG_VERY_VERY_VERBOSE, "readSock: peekAhead: $buf");
            } else {
                $end = strpos($peekBuf, "\r\n");
                if ($end === false) {
                     // echo ("<br>CRLF not found");
                    if ($space = (strrpos($peekbuf, " ")) > 0) {
                        $end = $space;
                    } else {
                        $end = $len;
                    }
                } else if ($end == 0) { // empty, just advance
                    $end = 2;
                } else {
                    $end += 2;
                }
                // echo ("end = $end\n");
                $len = socket_recv($this->popSock, $buf, $end, 0);
                $this->logit (DEBUG_VERY_VERY_VERBOSE, "readSock: buf = $buf");
            }
        }
        return ($buf);
    }
    
    // this public function exists to combine the start lines (char at 0) with the continuation lines (space at 0)
    public function getFullLine() {
		$numTries = 0;
        $header = "";
        do {
            $header = $this->readSock(null, true);
			$numTries++;
			if ($header == "") {
				sleep(1);
			}
        } while (($header == "") && ($numTries < 5));
		if (($numTries >= 5) || ($header == "\r\n.\r\n")) { // apparently nothing left to read!
			return null;
		}
        $this->logit (DEBUG_VERY_VERBOSE, "getFullLine: first line=$header");
        $nextLine = $this->readSock(null, true);
        if ($nextLine != "\r\n") {
            $this->logit (DEBUG_VERY_VERBOSE, "getFullLine: next line=$nextLine");
			if ($nextLine == "\r\n.\r\n") {
				$this->readSock($nextLine, false);
				return null;
			}
            if (preg_match("/^\s/", $nextLine)) {
                $this->logit (DEBUG_VERBOSE, "getFullLine: next line starts with space");
                while (preg_match("/^\s/", $nextLine)) {
                    $header .= $nextLine;
                    $nextLine = $this->readSock(null, true);
                    $this->logit (DEBUG_VERY_VERBOSE, "getFullLine: appended to header and read $nextLine");
                }
                $this->readSock($nextLine, false);
            } else {
                $this->readSock($nextLine, false);
            }
        } else {
            $this->readSock($nextLine, false);
        }
        return $header;
    }

    public function getHeaders() {
    
        $inheaders = true; // start out with headers
        $inSignature = false;
        $boundaryStart = "";
        $boundaryFound = false;
        $contentType = 0;
        while ($inheaders) {
            $header = $this->getFullLine();
			if ($header == null) {
				return $headers;
			}
            
            $this->logit (DEBUG_VERBOSE, "getHeaders: header is $header");
            if (($contentType == CONTENT_TYPE_MULTIPART) && (false !== strpos($header, $boundaryStart))) {
                $this->logit (DEBUG_VERBOSE, "getHeaders: found headers end");
                $inheaders = false;
                $this->readSock($header, false); // push it back
            } else if (($contentType <= CONTENT_TYPE_TEXT_PLAIN) && ($header == "\r\n")) {
                $this->logit (DEBUG_VERBOSE, "getHeaders: found headers end");
                $inheaders = false;
            } else {
                $inSignature = preg_match("/Signature/", $header);
                if (!$inSignature) {
                    if ((false !== strpos($header, "Content")) || (false !== strpos($header, "boundary="))) { // line contains "Content" or boundary
                        // echo ("line contains Content or boundary:\n");
                        if (false !== ($contentTypePos = stripos($header, "Content-Type:"))) {
                            $this->logit (DEBUG_VERBOSE, "getHeaders: found content-type");
                            if (false !== ($mp = strpos($header, "multipart", $contentTypePos+14))) {
                                $this->logit (DEBUG_VERBOSE, "getHeaders: found multipart");
                                $contentType = CONTENT_TYPE_MULTIPART;
                                if (false !== ($bnd = strpos($header, "boundary=", $mp))) {
                                    $boundaryFound = true;
                                }
                                if ($boundaryFound) {
                                    $this->logit (DEBUG_VERBOSE, "getHeaders: found boundary");
                                    if (false !== strpos($header, "\"")) { // if boundary is quoted
                                        $startQuote = strpos($header, "\"", $bnd+8);
                                        $this->logit(DEBUG_VERY_VERBOSE, "getHeaders: looking for boundary at $startQuote");
                                        $endQuote = strpos($header, "\"", $startQuote+1);
                                        // echo ("<br>start at $startQuote, end at $endQuote");
                                        $boundary = substr($header, $startQuote + 1, $endQuote - $startQuote - 1);
                                    } else { // wouldn't you know it, gmail doesn't quote the boundary
                                        $startPos = strpos($header, "=") + 1; // go 1 past the equal sign
                                        $boundary = rtrim(substr($header, $startPos));
                                    }
                                    // echo ("boundary is $boundary\n");
                                    $boundaryStart = "--" . $boundary;
                                    $boundaryEnd = $boundaryStart . "--";
                                    $this->logit (DEBUG_VERBOSE, "getHeaders: boundary start = $boundaryStart boundary end = $boundaryEnd");
                                }
                            }
                            if (false !== strpos($header, "text/plain")) {
                                $this->logit (DEBUG_VERBOSE, "getHeaders: found content-type text/plain");
                                $contentType = CONTENT_TYPE_TEXT_PLAIN;
                            }
                        }
                    }
                }
                
                $headers[] = $header;
            }
        }
        return $headers;
     }
     
     
    public function getHeadersForMsgNum($msgNum) {
     
        $this->logit (DEBUG_NORMAL, "sending RETR $msgNum");
        socket_write($this->popSock, "RETR $msgNum\r\n");
        $result = socket_read($this->popSock, 6, PHP_BINARY_READ);
        $this->logit (DEBUG_NORMAL, "RETR result: $result");
        
        $headers = $this->getHeaders();
        $this->logit (DEBUG_NORMAL, "headers: ".print_r($headers, true));
        return $headers;
    }
    
    public function deleteMsg($i) {
    
        $this->logit (DEBUG_NORMAL, "sending DELE $i");
        socket_write($this->popSock, "DELE $i\r\n");
        $result = socket_read($this->popSock, 512, PHP_BINARY_READ);
        $this->logit (DEBUG_NORMAL, "DELE result: $result");
    }
    
    public function closePopSock() {
        
        $cmd = "QUIT";
        $this->logit (DEBUG_NORMAL, "sending $cmd to pop socket");
        socket_write($this->popSock, "$cmd\r\n");
        $result = socket_read($this->popSock, 512, PHP_BINARY_READ);
        $this->logit (DEBUG_NORMAL, "QUIT result: $result");
        
        socket_close($this->popSock);
    }
    
    public function closeSmtpSock() {
        
        $cmd = "QUIT";
        $this->logit (DEBUG_NORMAL, "sending $cmd to smtp socket");
        socket_write($this->smtpSock, $cmd."\r\n");
        $result = socket_read($this->smtpSock, 512, PHP_BINARY_READ);
        $this->logit (DEBUG_NORMAL, "$cmd result: $result");
        
        socket_close($this->smtpSock);
    }
    
    public function openSmtpConnection($mailhost, $smtpport, $user, $pass) {
        
        $madeConnection = false;
        
        $this->logit (DEBUG_NORMAL, "opening smtp socket to $mailhost");
        // first create the socket
        if (false !== ($this->smtpSock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP))) {
            $inet4 = gethostbyname($mailhost);
            // then connect to $mailhost
            if ($smtpport == null) $smtpport = 587;
            if (socket_connect($this->smtpSock, $inet4, $smtpport)) {
                socket_set_block($this->smtpSock);
                $result = socket_read($this->smtpSock, 512, PHP_BINARY_READ);
                $this->logit (DEBUG_NORMAL, "$result");
                // if the result was 220 (see RFC 2821 p.44)
                if (preg_match("/^220.*/", $result)) {
                    $uname = posix_uname();
                    $cmd = "EHLO $uname[nodename]";
                    $this->logit (DEBUG_NORMAL, "sending $cmd");
                    socket_write($this->smtpSock, "$cmd\r\n");
                    $result = socket_read($this->smtpSock, 512, PHP_BINARY_READ);
                    $this->logit (DEBUG_NORMAL, "EHLO result: $result");
                    // if the result was 250 or 251 or 252 heck if it starts with 25
                    if (preg_match("/^25.*/", $result)) {
                        $this->logit (DEBUG_VERY_VERBOSE, "sendAuth: user=$user pass=".$pass);
                        $cmd = "AUTH LOGIN ".base64_encode(rtrim($user));
                        $this->logit (DEBUG_NORMAL, "sending $cmd");
                        socket_write($this->smtpSock, $cmd."\r\n");
                        $result = socket_read($this->smtpSock, 512, PHP_BINARY_READ);
                        $this->logit (DEBUG_NORMAL, "AUTH LOGIN result: $result");
                        $cmd = base64_encode(rtrim($pass));
                        $this->logit (DEBUG_NORMAL, "sending $cmd");
                        socket_write($this->smtpSock, "$cmd\r\n");
                        $result = socket_read($this->smtpSock, 512, PHP_BINARY_READ);
                        $this->logit (DEBUG_NORMAL, "PASS result: $result");
                        if (preg_match("/^235.*/", $result)) {
                            $madeConnection = true;
                        }
                    }
                }
            }
        }
        return $madeConnection;
    }
    
    public function doMail($user) {
     
        $cmd = "MAIL FROM:$user";
        $this->logit (DEBUG_NORMAL, "sending $cmd");
        socket_write($this->smtpSock, $cmd."\r\n");
        $result = socket_read($this->smtpSock, 512, PHP_BINARY_READ);
        $this->logit (DEBUG_NORMAL, "MAIL FROM result: $result");
    }

    public function doRcpt($boardMember) {
        
        $cmd = "RCPT TO:$boardMember";
        $this->logit (DEBUG_NORMAL, "sending $cmd");
        socket_write($this->smtpSock, $cmd."\r\n");
        $result = socket_read($this->smtpSock, 512, PHP_BINARY_READ);
        $this->logit (DEBUG_NORMAL, "RCPT TO result: $result");
    }
    
    public function doData() {
        
        $cmd = "DATA";
        $this->logit (DEBUG_NORMAL, "sending $cmd");
        socket_write($this->smtpSock, $cmd."\r\n");
        $result = socket_read($this->smtpSock, 512, PHP_BINARY_READ);
        $this->logit (DEBUG_NORMAL, "DATA result: $result");
    }
    
    public function modifyFrom($header, $domain) {
        $this->logit (DEBUG_NORMAL, "modifyFrom: header is $header");
        $start = strpos($header, ":") + 1;
        $at = strpos($header, "@");
        if (preg_match('/\</', $header)) { // it's got a from<who@where.com>
            $this->logit(DEBUG_VERBOSE, "modifyFrom: found <");
            $lt = strpos($header, "<");
            $name = substr($header, $start, ($lt - $start) - 1);
            $email = substr($header, $lt + 1, ($at - $lt) - 1);
            return ("From: $name <$email@$domain>\r\n");
        } else {
            $name = substr($header, $start, $at - $start);
            return ("From: $name@$domain\r\n");
        }
    }
    
    public function sendHeaders($user, $headers, $domain) {
        $this->logit(DEBUG_VERBOSE, "sendHeaders: headers=".print_r($headers, true));
        
        $singleHeaders = array( // these are from rfc2821
            "orig-date" => 0,
            "from" => 0,
            "sender" => 0,
            "reply-to" => 1,    // we're setting ourselves
            "to" => 1,          // we're setting ourselves
            "cc" => 0,
            "bcc" => 0,
            "message-id" => 0,
            "in-reply-to" => 0,
            "references" => 0,
            "subject" => 0
        );
        $this->logit (DEBUG_NORMAL, "writing Reply-To:$user");
        socket_write($this->smtpSock, "Reply-To:$user\r\n");
        socket_write($this->smtpSock, "To:$user\r\n");
        foreach ($headers as $header) {
            $headTok = strtolower(substr($header, 0, strpos($header, ":")));
            $this->logit (DEBUG_VERY_VERBOSE, "headTok = $headTok");
            if (array_key_exists($headTok, $singleHeaders)) {
                if ($singleHeaders[$headTok] == 0) {
                    $singleHeaders[$headTok]++;
                    if ($headTok == "from") {
                        $header = $this->modifyFrom($header, $domain);
                    }
                    $this->logit(DEBUG_NORMAL, "writing singleton header $header");
                    socket_write($this->smtpSock, $header);  
                }
            } else if (strlen($header) > 2) { // no CRLF's
                $this->logit (DEBUG_VERBOSE, "writing header $header");
                socket_write($this->smtpSock, $header);     
            }
        }
    
        socket_write($this->smtpSock, "\r\n"); // end headers
    }
    public function transferMsg() {
        $reading = true;
        while ($reading) {
            if (false !== ($buf = $this->readSock(null, true))) {
                $this->logit (DEBUG_VERY_VERY_VERBOSE, "getMsg: buf is: $buf");
                if (false !== strpos($buf, "\r\n.\r\n")) {
                    $reading = false;
                }
                $this->logit (DEBUG_VERY_VERBOSE, "writing message $buf");
                socket_write($this->smtpSock, $buf);
             } else {
                $this->logit (DEBUG_VERY_VERBOSE, "readSock returned false");
            }
        }
        
        $result = socket_read($this->smtpSock, 512, PHP_BINARY_READ);
        $this->logit (DEBUG_VERBOSE, "message result: $result");
        return (preg_match("/^(250)|(354).*/", $result));
    }
    
    public function remail($members, $headers, $user, $domain) {
        
        $this->doMail($user);
        foreach ($members as $member) {
            $this->doRcpt($member);
        }
        $this->doData();
        $this->sendHeaders($user, $headers, $domain);
        return ($this->transferMsg());
    }
    
    ///////////////////////////
    // main logic
    //
    public function __construct() {
        date_default_timezone_set("America/Los_Angeles");
        $this->echo = isset($_REQUEST['echo']);
        $this->openLogFile();
        $this->getLists();
        $this->logit (DEBUG_NORMAL, "*** Hello, this is minorDomo *****\n");
    }
    
    public function run() {
		$totalMsgs = 0;
        if ($this->lists != null) {
            foreach ($this->lists as $list) {
                $this->openPopSock($list['mailhost'], $list['popport']);
                if ($this->popSock != null) {
                    if ($this->login($list['user'], $list['pass'])) {
                        $numMsgs = $this->getMsgCount();
                        if ($numMsgs > 0) {
							$totalMsgs += $numMsgs;
                            if ($this->openSmtpConnection($list['mailhost'], $list['smtpport'], $list['user'],  $list['pass'])) {
                                for ($i = 1; $i <= $numMsgs; $i++) {
                                    $headers = $this->getHeadersForMsgNum($i);
                                    if ($this->remail ($list['members'], $headers, $list['user'], $list['domain'])) {
                                        $this->deleteMsg($i);
                                    }
                                }
                                $this->closeSmtpSock();
                            }
                        }
                    }
                    $this->closePopSock();
                }
            }
        }
		return $totalMsgs;
    }
    
    public function stop() {
        if ($this->debug != DEBUG_OFF) {
            fclose($this->logfile);
        }
        exit();
    }
}
?>
