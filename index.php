<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/secret_keys.php';


// Create connection
$conn = mysqli_connect($servername, $username, $password, $dbname);
// Check connection
if (!$conn) {
  die("Connection failed: " . mysqli_connect_error());
}


use Orhanerday\OpenAi\OpenAi;

$open_ai = new OpenAi($open_ai_key, $OrganizationID);

// IMAP server details
$server = '{imap.gmail.com:993/imap/ssl}INBOX';

// Connect to the IMAP server
$imap = imap_open($server, $email_username, $email_password);

// Get the list of unread emails
$emails = imap_search($imap, 'UNSEEN');

// Loop through each email
if($emails) {
    foreach ($emails as $email_number) {
        // Get the email header information
        $header = imap_headerinfo($imap, $email_number);

        // Get the subject
        $subject = $header->subject;

        // Get the date
        $date = $header->date;

        // Get the sender information
        $from = $header->from[0]->mailbox . '@' . $header->from[0]->host;

        // Get the email body
        $body = imap_fetchbody($imap, $email_number, 1);

        // Get the attached files
        $attachments = array();

        if (isset($header->parts)) {
            foreach ($header->parts as $part) {
                if ($part->ifdisposition && $part->disposition == 'ATTACHMENT') {
                    $filename = $part->dparameters[0]->value;
                    $filepath = 'attachments/' . $filename;
                    $attachments[] = $filepath;
                    imap_savebody($imap, $filepath, $email_number, $part->part_number);
                }
            }
        }  

        foreach ($attachments as $attachment) {
            echo 'Attachment: <a href="' . $attachment . '">' . basename($attachment) . '</a><br>';
        }

        if($body) {
            $prompt = "Please summarize the following text:\n{$body}\n\nSummary:";
            $complete = $open_ai->completion([
                'model' => 'text-davinci-003',
                'prompt' => $prompt,
                'temperature' => 0.9,
                'max_tokens' => 1024,
                'n' => 1,
                'frequency_penalty' => 0,
                'presence_penalty' => 0.6,
            ]);

            $decodedResponse = json_decode($complete, true);
            $summary = $decodedResponse['choices'][0]['text'];

            if($summary)
            {
                // Print the email details
                // echo 'From: ' . $from . '<br>';
                // echo 'Date: ' . $date . '<br>';
                // echo 'Subject: ' . $subject . '<br>';
                // echo 'Body: ' . $body . '<br>';
                // echo 'Summary: ' . $summary . '<br>';

                $sql = "INSERT INTO records (sender_email_address, subject, email_body, summary)
                VALUES ('$from', '$subject', '$body', '$summary')";

                if (mysqli_query($conn, $sql)) {
                    // echo "New record created successfully";

                    //Create an instance; passing `true` enables exceptions
                    $mail = new PHPMailer(true);
                    try {
                        //Server settings
                        // $mail->SMTPDebug = SMTP::DEBUG_SERVER; //Enable verbose debug output
                        $mail->isSMTP(); //Send using SMTP
                        $mail->Host       = $smtpHost; //Set the SMTP server to send through
                        $mail->SMTPAuth   = true; //Enable SMTP authentication
                        $mail->Username   = $email_username; //SMTP username
                        $mail->Password   = $email_password; //SMTP password
                        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; //Enable implicit TLS encryption
                        $mail->Port = 465; //TCP port to connect to; use 587 if you have set `SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS`

                        //Recipients
                        $mail->setFrom($email_username, $email_name);
                        $mail->addAddress($from);
                        $mail->addReplyTo($email_username, $email_name);
                        // $mail->addCC('kpranav82@yahoo.in');
                        // $mail->addBCC('pranav@1touch-dev.com');

                        //Content
                        $mail->isHTML(true); //Set email format to HTML
                        $mail->Subject = $autogenerate_email_subject;
                        $mail->Body    = $summary;

                        $mail->send();
                    } catch (Exception $e) {
                        echo "Message could not be sent. Mailer Error: {$mail->ErrorInfo}"; die();
                    }
                } else {
                  echo "Error: " . $sql . "<br>" . mysqli_error($conn); die();
                }

                mysqli_close($conn);
            }
        }

    }
    echo "Successfully run";
}

// Close the IMAP connection
imap_close($imap);
?>
