<?php namespace App\Console\Commands;

use Illuminate\Console\Command;
use stdClass;

$curlService = new \Ixudra\Curl\CurlService;

class UpdateMailchimp extends Command {
	public $api_key = YOUR API KEY;
	// Your API KEY
	public $list_id = YOUR LIST ID;
	// Your Mailchimp List ID
	/**
	 * The console command name.
	 *
	 * @var string
	 */
	protected $name = 'mailchimp:sync';

	/**
	 * The console command description.
	 *
	 * @var string
	 */
	protected $description = 'Sync MailChimp subscriber list.';

	/**
	 * Function
	 *
	 * @return mixed
	 */
	public function mailchimp_curl_connect( $url, $request_type, $api_key,  $data = array() ) {
		$headers_content = 'Content-Type: application/json';
		$headers_authorization = 'Authorization: Basic '.base64_encode( 'user:'. $api_key);
		if($request_type === 'POST'){
			$response = \Curl::to($url)
	            ->withHeader($headers_content)
	            ->withHeader($headers_authorization)
	            ->withOption('RETURNTRANSFER', true)
	            ->withOption('TIMEOUT', 10)
	            ->withOption('SSL_VERIFYPEER', false)
	            ->withOption('CUSTOMREQUEST', $request_type)
	            ->withData(json_encode($data))
	            ->post();

	        return $response;
	    } elseif ($request_type === 'GET'){
	    	$url .= '?' . http_build_query($data);

	    	$response = \Curl::to($url)
	    		->withHeader($headers_content)
	            ->withHeader($headers_authorization)
	            ->withOption('RETURNTRANSFER', true)
	            ->withOption('TIMEOUT', 10)
	            ->withOption('SSL_VERIFYPEER', false)
	            ->get();

	        return $response;
	    }

	}

	public function checkstatus($batch_id){
			$url = 'https://' . substr($this->api_key,strpos($this->api_key,'-')+1) . '.api.mailchimp.com/3.0/batches/' .$batch_id;
			$status = json_decode($this->mailchimp_curl_connect( $url, 'GET', $this->api_key ));

			if ($status->status === 'pending' || $status->status === 'started'){
				echo ".";
				sleep(5);
				$this->checkstatus($batch_id);
			} elseif ($status->status === 'finished') {
				$completed_operations = intval($status->finished_operations) - intval($status->errored_operations);
				echo "Complete.\n".$completed_operations." Contacts synced to MailChimp list.\n";
				if($status->errored_operations !== 0){
					echo "There were ".$status->errored_operations." errors. Review the response_body_url to view errors.\n";
					print_r($status);
				}
			}

		}


	/**
	 * Execute the console command.
	 *
	 * @return mixed
	 */
	public function fire()
	{	
		$url = 'https://' . substr($this->api_key,strpos($this->api_key,'-')+1) . '.api.mailchimp.com/3.0/batches';
	 
		$data = new \stdClass();
		$data->operations = array();
		// SELECTING CONTACTS THAT HAVE NOT BEEN SYNCED YET.
	 	$contacts_to_sync = \App\Contact::where('synced', 0)
						 		->orderby('id')
					            ->get()
					            ->toArray();

		//CHECKING IF THERE ARE ANY CONTACTS TO SYNC
		if($contacts_to_sync){
			foreach ( $contacts_to_sync as $contact ) {
				$batch =  new stdClass();
				$contact_id = md5($contact['email']);
				$batch->method = 'POST';
				$batch->path = 'lists/' . $this->list_id . '/members';
				$batch->body = json_encode( array(
					'email_address' => $contact['email'],
					'status' => 'subscribed'
				) );
				$data->operations[] = $batch;

				//UPDATING SYNCED STATUS OF ADDED CONTACT
				$contact_to_update = \App\Contact::where('id', $contact['id'])
										->first();
				$contact_to_update->synced = 1;
				$contact_to_update->save();
			}
		 
			$result = json_decode($this->mailchimp_curl_connect( $url, 'POST', $this->api_key, $data ));
			echo "Updating Contacts";
			$this->checkstatus($result->id);
		} else {
			echo "Your MailChimp List is Up-to-date.";
		}
	}

}	
