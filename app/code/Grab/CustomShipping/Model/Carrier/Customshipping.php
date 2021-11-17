<?php

namespace Grab\CustomShipping\Model\Carrier;

use Exception;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;

/**
 * Custom shipping model
 */
class Customshipping extends AbstractCarrier implements CarrierInterface
{    
    protected $logger;
    /**
     * @var \Magento\Framework\HTTP\Client\Curl
    */
    protected $curl;

    /**
     * @var string
     */
    protected $_code = 'customshipping';

    /**
     * @var bool
     */
    protected $_isFixed = true;

    /**
     * @var \Magento\Shipping\Model\Rate\ResultFactory
     */
    private $rateResultFactory;

    /**
     * @var \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory
     */
    private $rateMethodFactory;

    /**
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory $rateErrorFactory
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Magento\Shipping\Model\Rate\ResultFactory $rateResultFactory
     * @param \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory $rateMethodFactory
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory $rateErrorFactory,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Shipping\Model\Rate\ResultFactory $rateResultFactory,
        \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory $rateMethodFactory,
        \Magento\Framework\HTTP\Client\Curl $curl,
        array $data = []
    ) {
        parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);
        // $this->curl = $curl;
        // $this->curl->addHeader("Content-Type", "application/json");
        // $this->curl->addHeader("Content-Length", 2000);
        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/custom.log');
        $this->logger = new \Zend\Log\Logger();
        $this->logger->addWriter($writer);
        $this->logger->info('Custom message'); 
        //$logger->info(print_r($object->getData(), true));
        $this->rateResultFactory = $rateResultFactory;
        $this->rateMethodFactory = $rateMethodFactory;
    }

    /**
     * Custom Shipping Rates Collector
     *
     * @param RateRequest $request
     * @return \Magento\Shipping\Model\Rate\Result|bool
     */
    public function collectRates(RateRequest $request)
    { try{

    
        if (!$this->getConfigFlag('active')) {
            return false;
        }

        /** @var \Magento\Shipping\Model\Rate\Result $result */
        $result = $this->rateResultFactory->create();

        /** @var \Magento\Quote\Model\Quote\Address\RateResult\Method $method */
        $method = $this->rateMethodFactory->create();

        $method->setCarrier($this->_code);
        $method->setCarrierTitle($this->getConfigData('title'));

        //$this->curl->addHeader("Authorization","Bearer eyJhbGciOiJSUzI1NiIsImtpZCI6Il9kZWZhdWx0IiwidHlwIjoiSldUIn0.eyJhdWQiOiI2ZDVmODUyYWJhYWI0OTNiOGNiMTVlM2UwYjgyYTRjNSIsImV4cCI6MTYzNzAxMDgzMCwiaWF0IjoxNjM2OTI0NDMwLCJpc3MiOiJodHRwczovL2lkcC5ncmFiLmNvbSIsImp0aSI6InhQRUt3b25nUVAya3Y4eUxuQlVFSHciLCJuYmYiOjE2MzY5MjQyNTAsInBpZCI6Ijc4NTdjNzUwLWVmNWMtNDhlZi04YzFjLTYwMThhOGI4MDIwNCIsInBzdCI6MSwic2NwIjoiW1wiN2MxNDk3NGQzZDBlNDYyYjgzZDM1OGYwNTViZjdiYzZcIl0iLCJzdWIiOiJUV09fTEVHR0VEX09BVVRIIiwic3ZjIjoiIiwidGtfdHlwZSI6ImFjY2VzcyJ9.j6wbGpFAYabEWv7raVlyPWgj-zFR2YFuwg6jA19u1saXncYnkT4QIvpztbejn4kT6xs6Ie0dyro1nfSLQ0SNMepBw04h43R09mjKC0IUN9jiRXMC38jWWKIpA5zA5i4BvKFv-wkleBSZuPhp6K2eGbo2tFQmwXXIqssG_LLl-XmBMPI_uku2PdF_2oaFmtz8kPDiot97PtCMZtCGldEVqU5IycLpNyaBGnCQeclYqw19qUD895Rax_Nik2EpKuOaHlnfYagxxbH0-y3Y-itRlAJwb9jLhtL42BtnWbyB9cxhxDRu7-G2caZD7EqYVQD7pQipp1kp8iVi1UP-DHiECw");
        // post method
        $authUrl = "https://api.stg-myteksi.com/grabid/v1/oauth2/token";
        $url = "https://partner-api.stg-myteksi.com/grab-express-sandbox/v1/deliveries/quotes";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $authUrl);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        // curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: Bearer eyJhbGciOiJSUzI1NiIsImtpZCI6Il9kZWZhdWx0IiwidHlwIjoiSldUIn0.eyJhdWQiOiI2ZDVmODUyYWJhYWI0OTNiOGNiMTVlM2UwYjgyYTRjNSIsImV4cCI6MTYzNzA0MzUxNiwiaWF0IjoxNjM2OTU3MTE2LCJpc3MiOiJodHRwczovL2lkcC5ncmFiLmNvbSIsImp0aSI6IkJmdGpMdmVQVF9pdEFvUjdNV1hmM2ciLCJuYmYiOjE2MzY5NTY5MzYsInBpZCI6Ijc4NTdjNzUwLWVmNWMtNDhlZi04YzFjLTYwMThhOGI4MDIwNCIsInBzdCI6MSwic2NwIjoiW1wiN2MxNDk3NGQzZDBlNDYyYjgzZDM1OGYwNTViZjdiYzZcIl0iLCJzdWIiOiJUV09fTEVHR0VEX09BVVRIIiwic3ZjIjoiIiwidGtfdHlwZSI6ImFjY2VzcyJ9.nY3DTH-fkJ7yo-UQSX5yqkjBOIg2DSJmyeLVwtXFnhr_whMOez_f-iezQPS1dgHqzYhR63fr23Ame-Qkb0zS18-fNrv-4kY1-1V6L_tkrFFvH_mhW_ZGvKmo6Uzi4HY2OAhOQAHgMeig9SqEuBgHoH84JQsI_wcXQ4squPbB1nfG41gKJRaeJF1ns1u_OSddxVx864VsztqWCjarmBlyJtW9RsRd3XQ8KKq-A_U_jbDmeyHwO97C9aM3wkACFvKrbuPicCSZEq9xEJ9FiQKrYhV2oQXRD9D6YM5JZk0HJFf4KAgqpRcwpUYnH_dFrtIu53ILDlcS6xbOqsKK_Zw6NQ'));
        // curl_setopt($ch, CURLOPT_POST, 1);
        // curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $authJson = json_encode(array(
            "client_id"=> "6d5f852abaab493b8cb15e3e0b82a4c5",
            "client_secret"=> "QKC7umd8kNN4Ctft",
            "grant_type" => "client_credentials",
            "scope"=> "grab_express.partner_deliveries"
        ));
        
        $grabJson = json_encode(array(
          "serviceType" => "INSTANT",
          "packages" => [
                           array(
                                  "name"=> "Truffle Fries",                                            
                                  "quantity"=> 2,
                                  "description"=> "Thin cut deep-fried potatoes topped with truffle oil",
                                  "price"=> 4,
                                  "dimensions"=>                                                                array(
                                                                                                                "height"=> 0,
                                         "width"=> 0,
                                         "depth"=> 0,
                                         "weight"=> 0
                                              )
                                )    
                         ],
          "origin" => array(
                            "address"=> "1 IJK View, Singapore 018936",
                            "keywords"=> "PQR Tower",
                            "cityCode"=> "SIN",
                            "coordinates"=> array(
                                "latitude"=> 1.2345678,
                                "longitude"=> 3.4567890
                              )
                           ),
          "destination"=> array(
                            "address"=> "1 ABC St, Singapore 078881",
                            "keywords"=> "XYZ Tower",
                            "cityCode"=> "SIN",
                            "coordinates"=> array(
                              "latitude"=> 1.2345876,
                              "longitude"=> 3.4567098
                            )
                               )
         )    
    ) ;
    $this->logger->info($request->getDestCountryId());
    $this->logger->info($request->getDestStreet());
   // $params = [ $grabJson ];
    $this->logger->info($authJson);
    curl_setopt($ch,CURLOPT_POSTFIELDS,$authJson);
    $response  = curl_exec($ch);
    $this->logger->info($response);
    $token = json_decode($response)->access_token;
    $this->logger->info($token);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json',
                                                'Authorization:Bearer '.$token));
    
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch,CURLOPT_POSTFIELDS,$grabJson);
    $finalResponse  = curl_exec($ch);
    $this->logger->info($finalResponse);
    curl_close($ch);
      
        $method->setMethod($this->_code);
        $method->setMethodTitle($this->getConfigData('name'));
        $shippingCost = json_decode($finalResponse)->quotes[0]->amount;//json_decode($resultCost)[0]->amount;
        //$shippingCost = (float)$this->getConfigData('shipping_cost');

        $method->setPrice($shippingCost);
        $method->setCost($shippingCost);

        $result->append($method);

        return $result;
    }
    catch(Exception $e){
        $this->logger->info($e);
                            }

    }

    /**
     * @return array
     */
    public function getAllowedMethods()
    {
        return [$this->_code => $this->getConfigData('name')];
    }
}
