<?php

namespace li3_amf\extensions\media;

use lithium\action\Dispatcher;
use lithium\action\Request;

/**
 * Handles AMF request handling and response addition (and output)
 *
 */
class Amf extends \lithium\core\Object {
	/**
	 * Main request object.
	 *
	 * @var Zend_Amf_Request
	 */
	protected $request;
	
	/**
	 * AMF Response.
	 *
	 * @var Zend_Amf_Response
	 */
	protected $response;
	
	/**
	 * 0 or 3, depending on AMF object encoding.
	 *
	 * @var int
	 */
	protected $objectEncoding;
	
	/**
	 * Constructor.
	 *
	 */
	public function __construct() {
		$this->request = new \Zend_Amf_Request_Http();
		$this->objectEncoding = $this->request->getObjectEncoding();
		$this->response = new \Zend_Amf_Response_Http();
		$this->response->setObjectEncoding($this->objectEncoding);
	}
	
	/**
	 * Builds a composite AMF response based on the response bodies inside the 
	 * original AMF request.
	 *
	 * @return Zend_Amf_Response_Http
	 */
	public function processResponseBodies() {
		$responseBodies = $this->request->getAmfBodies();
		foreach($responseBodies as $body) {
			//Extract params from request body
			$return = $this->extractUriAndParams($body);
			//Create fake request object
			$liRequest = new Request(array('data' => $return['params']));
			//Assign URL to request based on details
			if(isset($return['source'])) {
				$liRequest->url = '/' . $return['source'] . '/' . $return['method']; 
			} elseif(isset($return['targetURI'])) {
				$liRequest->url = '/' . $return['targetURI'];
			}
			//Assign request params
			$liRequest->params += $return['params'];
			//Dispatch the request normally, and get the controller data
			$controllerResponse = Dispatcher::run($liRequest);
			//Add on the response data (or error) to the current response
			if(isset($controllerResponse->body['error'])) {
				$netStatusEvent = new StdClass();
				$netStatusEvent->_explicitType = 'flex.messaging.messages.ErrorMessage';
				$netStatusEvent->faultString = $controllerResponse->body['error'];
				$newBody = new \Zend_Amf_Value_MessageBody($body->getResponseURI() . \Zend_AMF_Constants::STATUS_METHOD, null, $netStatusEvent);
				$this->response->addAmfBody($newBody);
			} else {
				$newBody = new \Zend_Amf_Value_MessageBody($body->getResponseURI() . \Zend_AMF_Constants::STATUS_METHOD, null, $controllerResponse->body);
				$this->response->addAmfBody($newBody);
			}
		}
		return $this->response;
	}
	
	/**
	 * Exracts URI and params info based on object encoding.
	 *
	 * @param Zend_Amf_Value_MessageBody $body 
	 * @return array
	 */
	public function extractUriAndParams($body) {
		try {
			if ($this->objectEncoding == \Zend_Amf_Constants::AMF0_OBJECT_ENCODING) {
				// AMF0 Object Encoding
				$targetURI = $body->getTargetURI();
				// Split the target string into its values.
				$source = substr($targetURI, 0, strrpos($targetURI, '.'));
				if ($source) {
					// Break off method name from namespace into source
					$method = substr(strrchr($targetURI, '.'), 1);
					$return = array('method' => $method, 'params' => $body->getData(), 'source' => $source);
				} else {
					// Just have a method name.
					$return = array('targetURI' => $targetURI, 'params' => $body->getData());
				}
			} else {
				// AMF3 read message type
				$message = $body->getData();
				if ($message instanceof \Zend_Amf_Value_Messaging_CommandMessage) {
					// async call with command message
					switch($message->operation) {
						case \Zend_Amf_Value_Messaging_CommandMessage::CLIENT_PING_OPERATION :
							require_once 'Zend/Amf/Value/Messaging/AcknowledgeMessage.php';
							$return = new \Zend_Amf_Value_Messaging_AcknowledgeMessage($message);
							break;
						default :
							require_once 'Zend/Amf/Server/Exception.php';
							throw new \Zend_Amf_Server_Exception('CommandMessage::' . $message->operation . ' not implemented');
							break;
					}
				} elseif ($message instanceof \Zend_Amf_Value_Messaging_RemotingMessage) {
					require_once 'Zend/Amf/Value/Messaging/AcknowledgeMessage.php';
					$return = new \Zend_Amf_Value_Messaging_AcknowledgeMessage($message);
					$return->body = array('method' => $message->operation, 'params' => $message->body, 'source' => $message->source);
				} else {
					// Amf3 message sent with netConnection
					$targetURI = $body->getTargetURI();
					// Split the target string into its values.
					$source = substr($targetURI, 0, strrpos($targetURI, '.'));
					if ($source) {
						// Break off method name from namespace into source
						$method = substr(strrchr($targetURI, '.'), 1);
						$return = array('method' => $method, 'params' => array($body->getData()), 'source' => $source);
					} else {
						// Just have a method name.
						$return = array('targetURI' => $targetURI, 'params' => $body->getData());
					}
				}
			}
			$responseType = \Zend_AMF_Constants::RESULT_METHOD;
		} catch (Exception $e) {
			$isDebug = Configure::read('debug') > 0;
			switch ($objectEncoding) {
				case \Zend_Amf_Constants::AMF0_OBJECT_ENCODING :
					$return = array(
						'description' => ($isDebug) ? '' : $e->getMessage(),
						'detail'      => ($isDebug) ? '' : $e->getTraceAsString(),
						'line'        => ($isDebug) ? 0  : $e->getLine(),
						'code'        => $e->getCode(),
						);
					break;
				case \Zend_Amf_Constants::AMF3_OBJECT_ENCODING :
					require_once 'Zend/Amf/Value/Messaging/ErrorMessage.php';
					$return = new \Zend_Amf_Value_Messaging_ErrorMessage($message);
					$return->faultString = $this->isProduction() ? '' : $e->getMessage();
					$return->faultCode   = $e->getCode();
					$return->faultDetail = $this->isProduction() ? '' : $e->getTraceAsString();
					break;
			}
			$responseType = \Zend_AMF_Constants::STATUS_METHOD;
		}
		return $return;
	}
}

?>