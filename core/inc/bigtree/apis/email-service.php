<?
	/*
		Class: BigTreeEmailService
			A common interface for sending email through various transactional email API providers.
	*/
	
	class BigTreeEmailService {
		var $Service = "";

		/*
			Constructor:
				Sets up the currently configured service.
		*/
		
		function __construct() {
			$s = BigTreeAdmin::getSetting("bigtree-internal-email-service");

			// Setting doesn't exist? Create it.
			if ($s === false) {
				sqlquery("INSERT INTO bigtree_settings (`id`,`system`,`encrypted`) VALUES ('bigtree-internal-email-service','on','on')");
				$s = array("service" => "", "settings" => array());
				BigTreeAdmin::updateSettingValue("bigtree-internal-email-service",$s);
			}

			$this->Service = !empty($s["value"]["service"]) ? $s["value"]["service"] : "local";
			$this->Settings = !empty($s["value"]["settings"]) ? $s["value"]["settings"] : array();
		}

		/*
			Function: sendEmail
				Sends an HTML email.

			Parameters:
				subject - Email subject
				body - HTML email body
				to - Email address to send to (single address as a string or an array of email addresses)
				from_email - From email address (optional, defaults to no-reply@domain.com where domain.com is the domain of the server/site)
				from_name - From name (optional, defaults to BigTree CMS if from_email isn't set)
				reply_to - Reply-to email address (optional)
				text - Regular text body (optional, will strip_tags body if left a falsey value)

			Returns:
				true if successful
				Sets $this->Error with error response if not successful.
		*/

		function sendEmail($subject,$body,$to,$from_email = false,$from_name = false,$reply_to = false,$text = false) {
			$text = $text ? $text : strip_tags($body);
			if (!$from_email) {
				$from_email = "no-reply@".(isset($_SERVER["HTTP_HOST"]) ? str_replace("www.","",$_SERVER["HTTP_HOST"]) : str_replace(array("http://www.","https://www.","http://","https://"),"",DOMAIN));
				$from_name = "BigTree CMS";
			}
			
			if ($this->Service == "local") {
				BigTree::sendEmail($to,$subject,$body,$text,($from_name ? "$from_name <$from_email>" : $from_email),$reply_to);
			} elseif ($this->Service == "mandrill") {
				$this->sendMandrill($subject,$body,$to,$from_email,$from_name,$reply_to,$text);
			} elseif ($this->Service == "mailgun") {				
				$this->sendMailgun($subject,$body,$to,$from_email,$from_name,$reply_to,$text);
			} elseif ($this->Service == "postmark") {
				$this->sendPostmark($subject,$body,$to,$from_email,$from_name,$reply_to,$text);
			} else {
				throw Exception("Unknown Email Service");
			}
		}

		protected function sendMandrill($subject,$body,$to,$from_email,$from_name,$reply_to = false,$text = false) {
			// Generate array of people to send to
			$to_array = array();
			if (is_string($to)) {
				$to_array[] = array("email" => $to);
			} else {
				foreach ($to as $email) {
					$to_array[] = array("email" => $email);
				}
			}

			// Set reply header if passed in
			$headers = array();
			if ($reply_to) {
				$headers["Reply-To"] = $reply_to;
			}

			$response = json_decode(BigTree::cURL("https://mandrillapp.com/api/1.0/messages/send.json",json_encode(array(
				"key" => $this->Settings["mandrill_key"],
				"message" => array(
					"html" => $body,
					"text" => $text,
					"subject" => $subject,
					"from_email" => $from_email,
					"from_name" => $from_name,
					"to" => $to_array,
					"headers" => $headers
				)
			))),true);

			if ($response["status"] == "error") {
				$this->Error = $response["message"];
				return false;
			}

			return true;
		}

		protected function sendMailgun($subject,$body,$to,$from_email,$from_name,$reply_to = false,$text = false) {
			global $bigtree;

			// Build POST array
			$post = array(
				"from" => $from_name ? "$from_name <$from_email>" : $from_email,
				"to" => is_array($to) ? implode(",",$to) : $to,
				"subject" => $subject,
				"text" => $text,
				"html" => $body
			);
			
			// Add Reply-To header
			if ($reply_to) {
				$post["h:Reply-To"] = $reply_to;
			}

			// Mailgun doesn't give a nice easy to know error response so we have to check HTTP response codes
			$response = json_decode(BigTree::cURL("https://api.mailgun.net/v2/".$this->Settings["mailgun_domain"]."/messages",$post,array(CURLOPT_USERPWD => "api:".$this->Settings["mailgun_key"])),true);
			if ($bigtree["last_curl_response_code"] == 200) {
				return true;
			} else {
				$this->Error = $response["message"];
				return false;
			}
		}

		protected function sendPostmark($subject,$body,$to,$from_email,$from_name,$reply_to = false,$text = false) {
			// Build POST data
			$data = array(
				"From" => $from_name ? "$from_name <$from_email>" : $from_email,
				"To" => is_array($to) ? implode(",",$to) : $to,
				"Subject" => $subject,
				"HtmlBody" => $body,
				"TextBody" => $text
			);
			
			// Add reply to info
			if ($reply_to) {
				$data["ReplyTo"] = $reply_to;
			}

			$response = json_decode(BigTree::cURL("https://api.postmarkapp.com/email",json_encode($data),array(CURLOPT_HTTPHEADER => array(
				"Content-Type: application/json",
				"Accept: application/json",
				"X-Postmark-Server-Token: ".$this->Settings["postmark_key"]
			))),true);
			
			if ($response["ErrorCode"]) {
				$this->Error = $response["Message"];
				return false;
			}

			return true;
		}
	}
?>