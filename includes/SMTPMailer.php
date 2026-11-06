<?php
/**
 * Simple SMTP Mailer Class for Gmail (TLS)
 * A lightweight alternative to PHPMailer for simple use cases.
 */

class SMTPMailer {
    private $host;
    private $port;
    private $username;
    private $password;
    private $debug = false;

    public function __construct($host, $port, $username, $password) {
        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
    }

    public function setDebug($debug) {
        $this->debug = $debug;
    }

    private $lastError = '';

    public function getLastError() {
        return $this->lastError;
    }

    public function send($to, $subject, $body, $fromName, $fromEmail) {
        $this->lastError = '';
        try {
            $socket = stream_socket_client($this->host . ':' . $this->port, $errno, $errstr, 10); // Reduced timeout to 10s
            if (!$socket) {
                $this->lastError = "Connection failed: $errstr ($errno)";
                error_log("SMTP Error: " . $this->lastError);
                return false;
            }

            // Read banner
            $this->read($socket);

            // EHLO
            $this->write($socket, 'EHLO ' . gethostname());
            $this->read($socket);

            // STARTTLS
            $this->write($socket, 'STARTTLS');
            $this->read($socket);

            // Enable Crypto (TLS 1.2 preferred)
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT)) {
                $this->lastError = "TLS handshake failed.";
                error_log("SMTP Error: " . $this->lastError);
                fclose($socket);
                return false;
            }

            // EHLO after STARTTLS
            $this->write($socket, 'EHLO ' . gethostname());
            $this->read($socket);

            // AUTH LOGIN
            $this->write($socket, 'AUTH LOGIN');
            $this->read($socket);

            // Username
            $this->write($socket, base64_encode($this->username));
            $this->read($socket);

            // Password
            $this->write($socket, base64_encode($this->password));
            $resp = $this->read($socket);
            if (strpos($resp, '235') === false) {
                $this->lastError = "Authentication failed. Server said: " . trim($resp);
                error_log("SMTP Error: " . $this->lastError);
                fclose($socket);
                return false;
            }

            // MAIL FROM
            $this->write($socket, "MAIL FROM: <$fromEmail>");
            $this->read($socket);

            // RCPT TO
            $this->write($socket, "RCPT TO: <$to>");
            $this->read($socket);

            // DATA
            $this->write($socket, 'DATA');
            $this->read($socket);

            // Headers & Body
            $domain = substr(strrchr($fromEmail, "@"), 1);
            $messageId = "<" . uniqid() . "." . bin2hex(random_bytes(4)) . "@" . $domain . ">";

            $headers  = "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            $headers .= "Date: " . date("r") . "\r\n";
            $headers .= "From: $fromName <$fromEmail>\r\n";
            $headers .= "Reply-To: $fromName <$fromEmail>\r\n";
            $headers .= "To: $to\r\n";
            $headers .= "Subject: $subject\r\n";
            $headers .= "Message-ID: $messageId\r\n";
            $headers .= "X-Mailer: VN Dental Clinic Mailer v1.0\r\n";
            $headers .= "Sender: $fromEmail\r\n";
            $headers .= "Organization: Vanessa Nicolas Dental Clinic\r\n";
            $headers .= "X-Priority: 3\r\n";
            
            $this->write($socket, $headers . "\r\n" . $body . "\r\n.");
            $resp = $this->read($socket);

            // QUIT
            $this->write($socket, 'QUIT');
            fclose($socket);

            if (strpos($resp, '250') !== false) {
                return true;
            } else {
                $this->lastError = "Message rejected. Server said: " . trim($resp);
                error_log("SMTP Error: " . $this->lastError);
                return false;
            }
        } catch (Exception $e) {
            $this->lastError = "Exception: " . $e->getMessage();
            error_log("SMTP Exception: " . $this->lastError);
            return false;
        }
    }

    private function write($socket, $data) {
        if ($this->debug) echo "C: $data\n";
        fwrite($socket, $data . "\r\n");
    }

    private function read($socket) {
        $response = '';
        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            if (substr($line, 3, 1) == ' ') break;
        }
        if ($this->debug) echo "S: $response\n";
        return $response;
    }
}
