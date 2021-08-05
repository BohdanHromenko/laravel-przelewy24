<?php

namespace Devpark\Transfers24\Services\Handlers;

use Devpark\Transfers24\Contracts\IResponse;
use Devpark\Transfers24\Credentials;
use Devpark\Transfers24\Exceptions\EmptyCredentialsException;
use Devpark\Transfers24\Exceptions\NoEnvironmentChosenException;
use Devpark\Transfers24\Responses\InvalidResponse;
use Devpark\Transfers24\Responses\TestConnection;
use Devpark\Transfers24\Services\Gateways\Transfers24 as GatewayTransfers24;
use Devpark\Transfers24\Responses\Register as ResponseRegister;
use Devpark\Transfers24\Responses\Verify as ResponseVerify;
use Devpark\Transfers24\Responses\Http\Response as HttpResponse;
use Devpark\Transfers24\ErrorCode;
use Illuminate\Config\Repository;
use Psr\Log\LoggerInterface;

/**
 * Class Transfers24.
 */
class Transfers24
{
    /**
     * Error key in transfers24 response.
     */
    const ERROR_LABEL = 'error';

    /**
     * Token key in transfers24 response.
     */
    const TOKEN_LABEL = 'token';

    /**
     * Error description key in transfers24 response.
     */
    const MESSAGE_LABEL = 'errorMessage';

    /**
     * @var HttpResponse
     */
    protected $http_response;

    /**
     * @var int
     */
    protected $status_code;

    /**
     * @var string|null
     */
    protected $token = null;

    /**
     * @var string|null
     */
    protected $order_id = null;

    /**
     * @var string|null
     */
    protected $session_id = null;

    /**
     * @var array
     */
    protected $error_message = [];

    /**
     * @var array
     */
    protected $request_parameters = [];

    /**
     * @var array
     */
    protected $receive_parameters = [];
    /**
     * @var Repository
     */
    protected $config;
    /**
     * @var LoggerInterface
     */
    protected $logger;
    /**
     * @var Credentials
     */
    private $credentials_keeper;

    /**
     * Transfers24 constructor.
     *
     * @param GatewayTransfers24 $transfers24
     */
    public function __construct(GatewayTransfers24 $transfers24, Repository $config, LoggerInterface $logger)
    {
        $this->transfers24 = $transfers24;
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Register new payment in transfers24.
     *
     * @param array $fields
     *
     * @return ResponseRegister|InvalidResponse
     */
    public function init(array $fields):IResponse
    {
        try{
            $this->configureGateway();

            $this->session_id = $fields['p24_session_id'];
            $this->http_response = $this->transfers24->trnRegister($fields);
            $this->convertResponse();

            return new ResponseRegister($this);

        } catch (EmptyCredentialsException $exception)
        {
            $this->logger->error($exception->getMessage());
            return new InvalidResponse($exception);

        }catch (NoEnvironmentChosenException $exception)
        {
            $this->logger->error($exception->getMessage());
            return new InvalidResponse($exception);
        }
        catch (\Throwable $exception)
        {
            return new InvalidResponse($exception);
        }
    }

    /**
     * Verify payment after receiving callback.
     *
     * @param $post_data
     * @param bool $verify_check_sum
     *
     * @return ResponseVerify|IResponse
     */
    public function receive($post_data, $verify_check_sum = true):IResponse
    {
        try{
            $this->configureGateway();

            $this->receive_parameters = $post_data;

            $check_sum = $verify_check_sum ? $this->transfers24->checkSum($post_data) : true;

            if ($check_sum) {
                $this->session_id = $this->receive_parameters['p24_session_id'];
                $this->order_id = $this->receive_parameters['p24_order_id'];

                $fields = [
                    'p24_session_id' => $this->session_id,
                    'p24_order_id' => $this->order_id,
                    'p24_amount' => $this->receive_parameters['p24_amount'],
                    'p24_currency' => $this->receive_parameters['p24_currency'],
                ];

                $this->http_response = $this->transfers24->trnVerify($fields);

                $this->convertResponse();
            }

            return new ResponseVerify($this);

        } catch (EmptyCredentialsException $exception)
        {
            $this->logger->error($exception->getMessage());
            return new InvalidResponse($exception);

        }catch (NoEnvironmentChosenException $exception)
        {
            $this->logger->error($exception->getMessage());
            return new InvalidResponse($exception);
        }
        catch (\Throwable $exception)
        {
            return new InvalidResponse($exception);
        }
    }

    /**
     * Generation url to registered payment with token.
     *
     * @param $token
     * @param bool $redirect
     *
     * @return string
     */
    public function execute($token, $redirect = false):string
    {
        try{
            $this->configureGateway();

            return $this->transfers24->trnRequest($token, $redirect);

        } catch (EmptyCredentialsException $exception)
        {
            $this->logger->error($exception->getMessage());
            return $exception->getMessage();

        }catch (NoEnvironmentChosenException $exception)
        {
            $this->logger->error($exception->getMessage());
            return $exception->getMessage();
        }
        catch (\Throwable $exception)
        {
            return $exception->getMessage();
        }
    }

    /**
     * Test connection with Provider
     *
     * @return TestConnection|InvalidResponse
     */
    public function checkCredentials(): IResponse
    {
            try{
                $this->configureGateway();

                $this->http_response = $this->transfers24->testConnection();
                $this->convertResponse();

                return new TestConnection($this);

            } catch (EmptyCredentialsException $exception)
            {
                $this->logger->error($exception->getMessage());
                return new InvalidResponse($exception);

            }catch (NoEnvironmentChosenException $exception)
            {
                $this->logger->error($exception->getMessage());
                return new InvalidResponse($exception);
            }
            catch (\Throwable $exception)
            {
                return new InvalidResponse($exception);
            }
    }


        /**
     * Set properties from HttpResponse.
     *
     * @return void
     */
    public function convertResponse()
    {
        $this->request_parameters = $this->http_response->getFormParams();

        $response_table = [];
        parse_str($this->http_response->getBody(), $response_table);

        foreach ($response_table as $label => $segment) {
            switch ($label) {
                case self::ERROR_LABEL:
                    $description_error = ErrorCode::getDescription($segment);
                    if (! is_null($description_error)) {
                        $this->error_message[$segment] = $description_error;
                    }
                    $this->status_code = $segment;
                    break;
                case self::TOKEN_LABEL:
                    $this->token = $segment;
                    break;
                case self::MESSAGE_LABEL:
                    $this->segmentToDescription($segment);
                    break;
                default:
                    $error = ErrorCode::findAccurateCode(array_keys($response_table)[0]);
                    if(! empty($error))
                    {
                        $this->status_code = $error;
                        $this->error_message[$this->status_code] = ErrorCode::getDescription($this->status_code);
                    }
            }
        }
    }

    /**
     * Get error pair key and value from string and store in error_message.
     *
     * @param string $segment
     *
     * @return void
     */
    protected function segmentToDescription($segment)
    {
        $transform_error_segment = explode(':', $segment);
        if(count($transform_error_segment) > 1)
        {
            $this->error_message[$transform_error_segment[0]] = $transform_error_segment[1];
        }
        $this->error_message[] = $segment;
    }

    /**
     * Get Token for payment.
     *
     * @return string
     */
    public function getToken()
    {
        return $this->token;
    }

    /**
     * Get Code for payment.
     *
     * @return int
     */
    public function getCode()
    {
        return $this->status_code;
    }

    /**
     * Get Error description for payment.
     *
     * @return array
     */
    public function getErrorDescription()
    {
        return $this->error_message;
    }

    /**
     * Get Request parameters send to Transfers24.
     *
     * @return array
     */
    public function getRequestParameters()
    {
        return $this->request_parameters;
    }

    /**
     * Get Receive parameters send from Transfers24.
     *
     * @return array
     */
    public function getReceiveParameters()
    {
        return $this->receive_parameters;
    }

    /**
     * Get Transaction number received from transfers24.
     *
     * @return string
     */
    public function getOrderId()
    {
        return $this->order_id;
    }

    /**
     * Get Session number of payment.
     *
     * @return string
     */
    public function getSessionId()
    {
        return $this->session_id;
    }

    public function viaCredentials(Credentials $credentials): self
    {
        $this->credentials_keeper = $credentials;

        return $this;
    }

    /**
     * @throws EmptyCredentialsException
     * @throws NoEnvironmentChosenException
     */
    protected function configureGateway(): void
    {
        if ($this->config->get('transfers24.credentials-scope')) {
            if (!isset($this->credentials_keeper)) {
                throw new EmptyCredentialsException("Empty credentials.");
            }
            $this->transfers24->configure(
                $this->credentials_keeper->getPosId(),
                $this->credentials_keeper->getMerchantId(),
                $this->credentials_keeper->getCrc(),
                $this->credentials_keeper->isTestMode()
            );
        }
    }
}
