<?php

function mollieideal_config() {
    $configarray = array(
			"FriendlyName" => array(
			"Type" => "System",
			"Value"=>"iDeal via Mollie"
		),
		"partnerid" => array(
			"FriendlyName" => "Mollie Partner ID",
			"Type" => "text", 
			"Size" => "10",  
			"Description" => "You can activate the testmode on <a href=\"https://www.mollie.nl/beheer/betaaldiensten/instellingen/\" target=\"_blank\">www.mollie.nl/beheer/betaaldiensten/instellingen</a>"
		),
		"profilekey" => array(
			"FriendlyName" => "Profile key",
			"Type" => "text",
			"Size" => "8",  
			"Description" => "(Optional) You can find your profile key on <a href=\"https://www.mollie.nl/beheer/betaaldiensten/profielen/\">www.mollie.nl/beheer/betaaldiensten/profielen/</a>"
		),
		"customDescription" => array(
			"FriendlyName" => "Transaction description", 
			"Type" => "text", 
			"Size" => "30", 
			"Description" => "If you leave this blank, you're customers will see: <i>Your Company Name - Invoice ?{invoice ID}</i>"
		)
    );
	return $configarray;
}

function mollieideal_link($params) {

	# Gateway Specific Variables
	$gatewaypartnerid = $params['partnerid'];
	$gatewayprofilekey = $params['profilekey'];
	if(empty($params['customDescription']))
		$gatewaydescription = $params['description'];
	else
		$gatewaydescription = $params['customDescription'];

	$return_url  = $params['returnurl']; // URL waarnaar de consument teruggestuurd wordt na de betaling
	$report_url  = $params['systemurl'] . '/modules/gateways/callback/mollieideal.php?invoiceid=' . urlencode($params['invoiceid']) . '&amount=' . urlencode($params['amount']) . '&fee=' . urlencode($params['fee']); // URL die Mollie aanvraagt (op de achtergrond) na de betaling om de status naar op te sturen

	# Invoice Variables
	$invoiceid = $params['invoiceid'];
	$description = $params["description"];
    $amount = $params['amount']; # Format: ##.##
    $currency = $params['currency']; # Currency Code

	# Client Variables
	$firstname = $params['clientdetails']['firstname'];
	$lastname = $params['clientdetails']['lastname'];
	$email = $params['clientdetails']['email'];
	$address1 = $params['clientdetails']['address1'];
	$address2 = $params['clientdetails']['address2'];
	$city = $params['clientdetails']['city'];
	$state = $params['clientdetails']['state'];
	$postcode = $params['clientdetails']['postcode'];
	$country = $params['clientdetails']['country'];
	$phone = $params['clientdetails']['phonenumber'];

	# System Variables
	$companyname = $params['companyname'];
	$systemurl = $params['systemurl'];
	$currency = $params['currency'];

	# Enter your code submit to the gateway...

	if (!in_array('ssl', stream_get_transports()))
	{
		$code = "<h1>Foutmelding</h1>";
		$code .= "<p>Uw PHP installatie heeft geen SSL ondersteuning. SSL is nodig voor de communicatie met de Mollie iDEAL API.</p>";
		return $code;
	}
	
	$iDEAL = new iDEAL_Payment ($gatewaypartnerid);
	$iDEAL->setProfileKey($gatewayprofilekey);
	
	if (isset($_POST['bank_id']) and !empty($_POST['bank_id'])) 
	{
		$idealAmount = $amount*100;
	
		if ($iDEAL->createPayment($_POST['bank_id'], $idealAmount, $gatewaydescription, $return_url, $report_url)) 
		{
			/* Hier kunt u de aangemaakte betaling opslaan in uw database, bijv. met het unieke transactie_id
			   Het transactie_id kunt u aanvragen door $iDEAL->getTransactionId() te gebruiken. Hierna wordt 
			   de consument automatisch doorgestuurd naar de gekozen bank. */
			
			header("Location: " . $iDEAL->getBankURL());
			exit;
		}
		else 
		{
			/* Er is iets mis gegaan bij het aanmaken bij de betaling. U kunt meer informatie 
			   vinden over waarom het mis is gegaan door $iDEAL->getErrorMessage() en/of 
			   $iDEAL->getErrorCode() te gebruiken. */
			
			$code = '<p>De betaling kon niet aangemaakt worden.</p>';
			
			$code .= '<p><strong>Foutmelding:</strong> '. $iDEAL->getErrorMessage(). '</p>';
			return $code;
		}
	}

	$bank_array = $iDEAL->getBanks();
	
	if ($bank_array == false)
	{
		return '<p>Er is een fout opgetreden bij het ophalen van de banklijst: ' .$iDEAL->getErrorMessage() .'</p>';
	}


	if($_GET['action'] != 'masspay')
	{
		$code = '<form method="post">
		<select name="bank_id">
			<option value="">Kies uw bank</option>';
		
		foreach ($bank_array as $bank_id => $bank_name) {
				$code .= '<option value="'. $bank_id .'">'. $bank_name .'</option>';
		}
	
		$code .= '</select>
		<input type="submit" name="submit" value="Betaal via iDEAL" />
	</form>';
	}

	return $code;
}

class iDEAL_Payment
{

	const     MIN_TRANS_AMOUNT = 118;

	protected $partner_id      = null;
	protected $profile_key     = null;
		
	protected $testmode        = false;

	protected $bank_id         = null;
	protected $amount          = 0;
	protected $description     = null;
	protected $return_url      = null;
	protected $report_url      = null;

	protected $bank_url        = null;
	protected $payment_url     = null;

	protected $transaction_id  = null;
	protected $paid_status     = false;
	protected $consumer_info   = array();

	protected $error_message   = '';
	protected $error_code      = 0;

	protected $api_host        = 'ssl://secure.mollie.nl';
	protected $api_port        = 443;

	public function __construct ($partner_id, $api_host = 'ssl://secure.mollie.nl', $api_port = 443)
	{
		$this->partner_id = $partner_id;
		$this->api_host   = $api_host;
		$this->api_port   = $api_port;
	}

	// Haal de lijst van beschikbare banken
	public function getBanks()
	{
		$query_variables = array (
			'a'          => 'banklist',
			'partner_id' => $this->partner_id,
		);

		if ($this->testmode) {
			$query_variables['testmode'] = 'true';
		}

		$banks_xml = $this->_sendRequest (
			$this->api_host,
			$this->api_port,
			'/xml/ideal/',
			http_build_query($query_variables, '', '&')
		);

		if (empty($banks_xml)) {
			return false;
		}

		$banks_object = $this->_XMLtoObject($banks_xml);

		if (!$banks_object or $this->_XMlisError($banks_object)) {
			return false;
		}

		$banks_array = array();

		foreach ($banks_object->bank as $bank) {
			$banks_array["{$bank->bank_id}"] = "{$bank->bank_name}";
		}

		return $banks_array;
	}

	// Zet een betaling klaar bij de bank en maak de betalings URL beschikbaar
	public function createPayment ($bank_id, $amount, $description, $return_url, $report_url)
	{
		if (!$this->setBankId($bank_id) or
			!$this->setDescription($description) or
			!$this->setAmount($amount) or
			!$this->setReturnUrl($return_url) or
			!$this->setReportUrl($report_url))
		{
			$this->error_message = "De opgegeven betalings gegevens zijn onjuist of incompleet.";
			return false;
		}

		$query_variables = array (
			'a'           => 'fetch',
			'partnerid'   => $this->getPartnerId(),
			'bank_id'     => $this->getBankId(),
			'amount'      => $this->getAmount(),
			'description' => $this->getDescription(),
			'reporturl'   => $this->getReportURL(),
			'returnurl'   => $this->getReturnURL(),
		);
		
		if ($this->profile_key)
			$query_variables['profile_key'] = $this->profile_key;

		$create_xml = $this->_sendRequest(
			$this->api_host,
			$this->api_port,
			'/xml/ideal/',
			http_build_query($query_variables, '', '&')			
		);

		if (empty($create_xml)) {
			return false;
		}

		$create_object = $this->_XMLtoObject($create_xml);

		if (!$create_object or $this->_XMLisError($create_object)) {
			return false;
		}

		$this->transaction_id = (string) $create_object->order->transaction_id;
		$this->bank_url       = (string) $create_object->order->URL;

		return true;
	}

	// Kijk of er daadwerkelijk betaald is
	public function checkPayment ($transaction_id)
	{
		if (!$this->setTransactionId($transaction_id)) {
			$this->error_message = "Er is een onjuist transactie ID opgegeven";
			return false;
		}
		
		$query_variables = array (
			'a'              => 'check',
			'partnerid'      => $this->partner_id,
			'transaction_id' => $this->getTransactionId(),
		);

		if ($this->testmode) {
			$query_variables['testmode'] = 'true';
		}

		$check_xml = $this->_sendRequest(
			$this->api_host,
			$this->api_port,
			'/xml/ideal/',
			http_build_query($query_variables, '', '&')
			);

		if (empty($check_xml))
			return false;

		$check_object = $this->_XMLtoObject($check_xml);

		if (!$check_object or $this->_XMLisError($check_object)) {
			return false;
		}

		$this->paid_status   = (bool) ($check_object->order->payed == 'true');
		$this->amount        = (int) $check_object->order->amount;
		$this->consumer_info = (isset($check_object->order->consumer)) ? (array) $check_object->order->consumer : array();

		return true;
	}

	public function CreatePaymentLink ($description, $amount)
	{
		if (!$this->setDescription($description) or !$this->setAmount($amount))
		{
			$this->error_message = "U moet een omschrijving �n bedrag (in centen) opgeven voor de iDEAL link. Tevens moet het bedrag minstens " . self::MIN_TRANS_AMOUNT . ' eurocent zijn. U gaf ' . (int) $amount . ' cent op.';
			return false;
		}
		
		$query_variables = array (
			'a'           => 'create-link',
			'partnerid'   => $this->partner_id,
			'amount'      => $this->getAmount(),
			'description' => $this->getDescription(),
		);

		$create_xml = $this->_sendRequest(
			$this->api_host,
			$this->api_port,
			'/xml/ideal/',
			http_build_query($query_variables, '', '&')
			);

		$create_object = $this->_XMLtoObject($create_xml);

		if (!$create_object or $this->_XMLisError($create_object)) {
			return false;
		}

		$this->payment_url = (string) $create_object->link->URL;
	}

/*
	PROTECTED FUNCTIONS
*/

	protected function _sendRequest ($host, $port, $path, $data)
	{
		if (function_exists('curl_init')) {
			return $this->_sendRequestCurl($host, $port, $path, $data);
		}
		else {
			return $this->_sendRequestFsock($host, $port, $path, $data);
		}
	}

	protected function _sendRequestFsock ($host, $port, $path, $data)
	{
		$hostname = str_replace('ssl://', '', $host);
		$fp = @fsockopen($host, $port, $errno, $errstr);
		$buf = '';

		if (!$fp)
		{
			$this->error_message = 'Kon geen verbinding maken met server: ' . $errstr;
			$this->error_code		= 0;

			return false;
		}

		@fputs($fp, "POST $path HTTP/1.0\n");
		@fputs($fp, "Host: $hostname\n");
		@fputs($fp, "Content-type: application/x-www-form-urlencoded\n");
		@fputs($fp, "Content-length: " . strlen($data) . "\n");
		@fputs($fp, "Connection: close\n\n");
		@fputs($fp, $data);

		while (!feof($fp)) {
			$buf .= fgets($fp, 128);
		}

		fclose($fp);

		if (empty($buf))
		{
			$this->error_message = 'Zero-sized reply';
			return false;
		}
		else {
			list($headers, $body) = preg_split("/(\r?\n){2}/", $buf, 2);
		}

		return $body;
	}
	
	protected function _sendRequestCurl ($host, $port, $path, $data)
	{
		$host = str_replace('ssl://', 'https://', $host);
		
		$ch = curl_init();
		
		curl_setopt($ch, CURLOPT_URL, $host . $path);
		curl_setopt($ch, CURLOPT_PORT, $port);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_TIMEOUT, 10);
		curl_setopt($ch, CURLOPT_HEADER, false);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $data);		
		
		$body = curl_exec($ch);
		
		curl_close($ch);
		
		return $body;	
	}

	protected function _XMLtoObject ($xml)
	{
		try
		{
			$xml_object = new SimpleXMLElement($xml);
			if ($xml_object == false)
			{
				$this->error_message = "Kon XML resultaat niet verwerken";
				return false;
			}
		}
		catch (Exception $e) {
			return false;
		}

		return $xml_object;
	}

	protected function _XMLisError($xml)
	{
		if (isset($xml->item))
		{
			$attributes = $xml->item->attributes();
			if ($attributes['type'] == 'error')
			{
				$this->error_message = (string) $xml->item->message;
				$this->error_code    = (string) $xml->item->errorcode;

				return true;
			}
		}

		if (isset($xml->order->error) && (string) $xml->order->error == "true") {
			$this->error_message = $xml->order->message;
			$this->error_code = -1;
			return true;
		}

		return false;
	}


	/* Getters en setters */
	public function setProfileKey($profile_key)
	{		
		if (is_null($profile_key))
			return false;
			
		return ($this->profile_key = $profile_key);
	}
	
	public function getProfileKey()
	{
		return $this->profile_key;
	}
	
	public function setPartnerId ($partner_id)
	{
		if (!is_numeric($partner_id)) {
			return false;
		}

		return ($this->partner_id = $partner_id);
	}

	public function getPartnerId ()
	{
		return $this->partner_id;
	}

	public function setTestmode ($enable = true)
	{
		return ($this->testmode = $enable);
	}

	public function setBankId ($bank_id)
	{
		if (!is_numeric($bank_id))
			return false;

		return ($this->bank_id = $bank_id);
	}

	public function getBankId ()
	{
		return $this->bank_id;
	}

	public function setAmount ($amount)
	{
		if (!preg_match('~^[0-9]+$~', $amount)) {
			return false;
		}

		if (self::MIN_TRANS_AMOUNT > $amount) {
			return false;
		}

		return ($this->amount = $amount);
	}

	public function getAmount ()
	{
		return $this->amount;
	}

	public function setDescription ($description)
	{
		$description = substr($description, 0, 29);

		return ($this->description = $description);
	}

	public function getDescription ()
	{
		return $this->description;
	}

	public function setReturnURL ($return_url)
	{
		if (!preg_match('|(\w+)://([^/:]+)(:\d+)?(.*)|', $return_url))
			return false;

		return ($this->return_url = $return_url);
	}

	public function getReturnURL ()
	{
		return $this->return_url;
	}

	public function setReportURL ($report_url)
	{
		if (!preg_match('|(\w+)://([^/:]+)(:\d+)?(.*)|', $report_url)) {
			return false;
		}

		return ($this->report_url = $report_url);
	}

	public function getReportURL ()
	{
		return $this->report_url;
	}

	public function setTransactionId ($transaction_id)
	{
		if (empty($transaction_id))
			return false;

		return ($this->transaction_id = $transaction_id);
	}

	public function getTransactionId ()
	{
		return $this->transaction_id;
	}

	public function getBankURL ()
	{
		return $this->bank_url;
	}

	public function getPaymentURL ()
	{
		return (string) $this->payment_url;
	}

	public function getPaidStatus ()
	{
		return $this->paid_status;
	}

	public function getConsumerInfo ()
	{
		return $this->consumer_info;
	}

	public function getErrorMessage ()
	{
		return $this->error_message;
	}

	public function getErrorCode ()
	{
		return $this->error_code;
	}
	
}

?>