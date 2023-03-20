<?php
namespace verbb\formie\integrations\payments;

use verbb\formie\Formie;
use verbb\formie\base\FormField;
use verbb\formie\base\FormFieldInterface;
use verbb\formie\base\Integration;
use verbb\formie\base\Payment;
use verbb\formie\elements\Submission;
use verbb\formie\events\ModifyFrontEndSubfieldsEvent;
use verbb\formie\events\ModifyPaymentCurrencyOptionsEvent;
use verbb\formie\events\ModifyPaymentPayloadEvent;
use verbb\formie\events\PaymentReceiveWebhookEvent;
use verbb\formie\fields\formfields;
use verbb\formie\fields\formfields\SingleLineText;
use verbb\formie\helpers\ArrayHelper;
use verbb\formie\helpers\SchemaHelper;
use verbb\formie\helpers\Variables;
use verbb\formie\models\IntegrationField;
use verbb\formie\models\Payment as PaymentModel;
use verbb\formie\models\Plan;

use Craft;
use craft\helpers\App;
use craft\helpers\Component;
use craft\helpers\DateTimeHelper;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\web\Response;

use yii\base\Event;

use GuzzleHttp\Client;

use Throwable;
use Exception;

class Opayo extends Payment
{
    // Constants
    // =========================================================================

    public const EVENT_MODIFY_PAYLOAD = 'modifyPayload';
    public const EVENT_MODIFY_FRONT_END_SUBFIELDS = 'modifyFrontEndSubfields';


    // Static Methods
    // =========================================================================

    /**
     * @inheritDoc
     */
    public static function displayName(): string
    {
        return Craft::t('formie', 'Opayo');
    }

    /**
     * @inheritDoc
     */
    public function supportsCallbacks(): bool
    {
        return true;
    }
    

    // Properties
    // =========================================================================

    public ?string $vendorName = null;
    public ?string $integrationKey = null;
    public ?string $integrationPassword = null;
    public bool|string $useSandbox = false;


    // Public Methods
    // =========================================================================

    public function getDescription(): string
    {
        return Craft::t('formie', 'Provide payment capabilities for your forms with Opayo.');
    }

    /**
     * @inheritDoc
     */
    public function hasValidSettings(): bool
    {
        return App::parseEnv($this->vendorName) && App::parseEnv($this->integrationKey) && App::parseEnv($this->integrationPassword);
    }

    /**
     * @inheritDoc
     */
    public function getFrontEndHtml($field, $renderOptions): string
    {
        if (!$this->hasValidSettings()) {
            return '';
        }

        $this->setField($field);

        return Craft::$app->getView()->renderTemplate('formie/integrations/payments/opayo/_input', [
            'field' => $field,
            'renderOptions' => $renderOptions,
        ]);
    }

    /**
     * @inheritDoc
     */
    public function getFrontEndJsVariables($field = null): ?array
    {
        if (!$this->hasValidSettings()) {
            return null;
        }

        $this->setField($field);

        $settings = [
            'useSandbox' => App::parseBooleanEnv($this->useSandbox),
            'currency' => $this->getFieldSetting('currency'),
            'amountType' => $this->getFieldSetting('amountType'),
            'amountFixed' => $this->getFieldSetting('amountFixed'),
            'amountVariable' => $this->getFieldSetting('amountVariable'),
        ];

        return [
            'src' => Craft::$app->getAssetManager()->getPublishedUrl('@verbb/formie/web/assets/frontend/dist/js/payments/opayo.js', true),
            'module' => 'FormieOpayo',
            'settings' => $settings,
        ];
    }

    /**
     * @inheritDoc
     */
    public function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['vendorName', 'integrationKey', 'integrationPassword'], 'required', 'on' => [Integration::SCENARIO_FORM]];

        return $rules;
    }

    /**
     * @inheritDoc
     */
    public function processPayment(Submission $submission): bool
    {
        $response = null;
        $result = false;

        // Allow events to cancel sending
        if (!$this->beforeProcessPayment($submission)) {
            return true;
        }        

        // Get the amount from the field, which handles dynamic fields
        $amount = $this->getAmount($submission);
        $currency = $this->getFieldSetting('currency');

        // Capture the authorized payment
        try {
            $field = $this->getField();
            $fieldValue = $submission->getFieldValue($field->handle);
            $opayoTokenId = $fieldValue['opayoTokenId'] ?? null;
            $opayoSessionKey = $fieldValue['opayoSessionKey'] ?? null;
            $opayo3DSComplete = $fieldValue['opayo3DSComplete'] ?? null;

            // Check if we've returned from a 3DS challenge. We've already captured the payment, and recorded the successful payment.
            if ($opayo3DSComplete) {
                // Verify that we indeed have a verified payment - just in case people are trying to send through _any_ value
                if (Formie::$plugin->getPayments()->getPaymentByReference($opayo3DSComplete)) {
                    // We can return true here to allow the form to continue with the submission process
                    return true;
                } else {
                    throw new Exception('Unable to find payment by "' . $opayo3DSComplete . '".');
                }
            }

            if (!$opayoTokenId || !is_string($opayoTokenId)) {
                throw new Exception("Missing `opayoTokenId` from payload: {$opayoTokenId}.");
            }

            if (!$opayoSessionKey || !is_string($opayoSessionKey)) {
                throw new Exception("Missing `opayoSessionKey` from payload: {$opayoSessionKey}.");
            }

            if (!$amount) {
                throw new Exception("Missing `amount` from payload: {$amount}.");
            }

            if (!$currency) {
                throw new Exception("Missing `currency` from payload: {$currency}.");
            }

            // Generate the payload data
            $payload = $this->_getPayload($opayoSessionKey, $opayoTokenId, $submission, $amount, $currency);

            // Raise a `modifySinglePayload` event
            $event = new ModifyPaymentPayloadEvent([
                'integration' => $this,
                'submission' => $submission,
                'payload' => $payload,
            ]);
            $this->trigger(self::EVENT_MODIFY_PAYLOAD, $event);

            // Trigger the Opato payment to be captured
            $response = $this->request('POST', 'transactions', ['json' => $event->payload]);

            $status = $response['status'] ?? null;
            $statusDetail = $response['statusDetail'] ?? null;

            // Was this a 3DS challenge? We need to redirect the user
            $acsUrl = $response['acsUrl'] ?? null;

            if ($acsUrl) {
                $payment = new PaymentModel();
                $payment->integrationId = $this->id;
                $payment->submissionId = $submission->id;
                $payment->fieldId = $field->id;
                $payment->amount = $amount;
                $payment->currency = $currency;
                $payment->reference = $response['transactionId'] ?? '';
                $payment->response = $response;
                $payment->status = PaymentModel::STATUS_PENDING;

                Formie::$plugin->getPayments()->savePayment($payment);

                $returnUrl = UrlHelper::siteUrl('formie/payment-webhooks/process-callback', ['handle' => $this->handle]);
                $threeDSSessionData = [
                    'submissionId' => $submission->id,
                    'fieldId' => $field->id,
                    'amount' => $amount,
                    'currency' => $currency,
                    'reference' => $response['transactionId'] ?? '',
                ];

                // Store the data we need for 3DS against the form, which is added is the Ajax response
                $submission->getForm()->addFrontEndJsEvents([
                    'event' => 'FormiePaymentOpayo3DS',
                    'data' => [
                        'acsUrl' => $acsUrl,
                        'creq' => $response['cReq'] ?? '',
                        'returnUrl' => $returnUrl,
                        'threeDSSessionData' => base64_encode(Json::encode($threeDSSessionData)),
                    ],
                ]);

                // Add an error to the form to ensure it doesn't proceed, and the 3DS popup is shown
                $submission->addError($field->handle, Craft::t('formie', 'This payment requires 3D Secure authentication. Please follow the instructions on-screen to continue.'));

                return false;
            }

            Craft::dd($response);

            if ($status !== 'Ok') {
                throw new Exception(StringHelper::titleize($status) . ': ' . $statusDetail);
            }

            $payment = new PaymentModel();
            $payment->integrationId = $this->id;
            $payment->submissionId = $submission->id;
            $payment->fieldId = $field->id;
            $payment->amount = $amount;
            $payment->currency = $currency;
            $payment->reference = $response['transactionId'] ?? '';
            $payment->response = $response;
            $payment->status = PaymentModel::STATUS_SUCCESS;

            Formie::$plugin->getPayments()->savePayment($payment);

            $result = true;
        } catch (Throwable $e) {
            // Save a different payload to logs
            Integration::error($this, Craft::t('formie', 'Payment error: “{message}” {file}:{line}. Response: “{response}”', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'response' => Json::encode($response),
            ]));

            Integration::apiError($this, $e, $this->throwApiError);

            $submission->addError($field->handle, Craft::t('formie', $e->getMessage()));
            
            $payment = new PaymentModel();
            $payment->integrationId = $this->id;
            $payment->submissionId = $submission->id;
            $payment->fieldId = $field->id;
            $payment->amount = $amount;
            $payment->currency = $currency;
            $payment->status = PaymentModel::STATUS_FAILED;
            $payment->reference = null;
            $payment->response = ['message' => $e->getMessage()];

            Formie::$plugin->getPayments()->savePayment($payment);

            return false;
        }

        // Allow events to say the response is invalid
        if (!$this->afterProcessPayment($submission, $result)) {
            return true;
        }

        return $result;
    }

    /**
     * @inheritdoc
     */
    public function processCallback(): Response
    {
        $request = Craft::$app->getRequest();
        $callbackResponse = Craft::$app->getResponse();
        $callbackResponse->format = Response::FORMAT_RAW;

        // Check to see if we're requesting a merchant session key - the first step
        if ($request->getParam('merchantSessionKey')) {
            $callbackResponse->format = Response::FORMAT_JSON;

            $response = $this->request('POST', 'merchant-session-keys', [
                'json' => ['vendorName' => App::parseEnv($this->vendorName)],
            ]);

            $callbackResponse->data = [
                'merchantSessionKey' => $response['merchantSessionKey'] ?? null,
            ];

            return $callbackResponse;
        }
        
        $responseData = [];

        $cres = $request->getParam('cres');
        $data = $request->getParam('threeDSSessionData');

        if (!$cres || !$data) {
            Integration::error($this, 'Callback not signed or signing secret not set.');
            $callbackResponse->data = 'ok';

            return $callbackResponse;
        }

        // Get the data sent to Opayo
        $data = Json::decode(base64_decode($data));
        $submissionId = $data['submissionId'] ?? null;
        $fieldId = $data['fieldId'] ?? null;
        $amount = $data['amount'] ?? null;
        $currency = $data['currency'] ?? null;
        $transactionId = $data['reference'] ?? null;

        try {
            // Process the 3DS challenge
            $response = $this->request('POST', "transactions/$transactionId/3d-secure-challenge", [
                'json' => [
                    'threeDSSessionData' => $transactionId,
                    'cRes' => $cres,
                ],
            ]);

            $status = $response['status'] ?? null;
            $statusDetail = $response['statusDetail'] ?? null;

            if ($status !== 'Ok') {
                throw new Exception(StringHelper::titleize($status) . ': ' . $statusDetail);
            }

            // Record the payment
            $payment = Formie::$plugin->getPayments()->getPaymentByReference($transactionId);

            if ($payment) {
                $payment->status = PaymentModel::STATUS_SUCCESS;
                $payment->reference = $transactionId;
                $payment->response = $response;

                Formie::$plugin->getPayments()->savePayment($payment);
            } else {
                throw new Exception('Unable to find payment by "' . $transactionId . '".');
            }

            $responseData['success'] = true;
            $responseData['transactionId'] = $transactionId;
        } catch (Throwable $e) {
            // Save a different payload to logs
            Integration::error($this, Craft::t('formie', 'Payment error: “{message}” {file}:{line}. Response: “{response}”', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'response' => Json::encode($response),
            ]));

            Integration::apiError($this, $e, $this->throwApiError);
            
            $payment = new PaymentModel();
            $payment->integrationId = $this->id;
            $payment->submissionId = $submissionId;
            $payment->fieldId = $fieldId;
            $payment->amount = $amount;
            $payment->currency = $currency;
            $payment->status = PaymentModel::STATUS_FAILED;
            $payment->reference = $transactionId;
            $payment->response = ['message' => $e->getMessage()];

            Formie::$plugin->getPayments()->savePayment($payment);

            $responseData['error'] = $payment->response;
        }

        // Send back some JS to trigger the iframe to close, and the submission to submit
        $callbackResponse->data = '<script>window.parent.postMessage({ message: "FormiePaymentOpayo3DSResponse", value: ' . Json::encode($responseData) . ' }, "*");</script>';

        return $callbackResponse;
    }

    /**
     * @inheritDoc
     */
    public function fetchConnection(): bool
    {
        try {
            $response = $this->request('POST', 'merchant-session-keys', [
                'json' => ['vendorName' => App::parseEnv($this->vendorName)],
            ]);
        } catch (Throwable $e) {
            Integration::apiError($this, $e);

            return false;
        }

        return true;
    }

    public function getClient(): Client
    {
        if ($this->_client) {
            return $this->_client;
        }

        $useSandbox = App::parseBooleanEnv($this->useSandbox);
        $url = $useSandbox ? 'https://pi-test.sagepay.com/' : 'https://pi.sagepay.com/';

        return $this->_client = Craft::createGuzzleClient([
            'base_uri' => $url . 'api/v1/',
            'auth' => [App::parseEnv($this->integrationKey), App::parseEnv($this->integrationPassword)],
        ]);
    }

    /**
     * @inheritDoc
     */
    public function defineGeneralSchema(): array
    {
        return [
            SchemaHelper::selectField([
                'label' => Craft::t('formie', 'Payment Currency'),
                'help' => Craft::t('formie', 'Provide the currency to be used for the transaction.'),
                'name' => 'currency',
                'required' => true,
                'validation' => 'required',
                'options' => array_merge(
                    [['label' => Craft::t('formie', 'Select an option'), 'value' => '']],
                    static::getCurrencyOptions()
                ),
            ]),
            [
                '$formkit' => 'fieldWrap',
                'label' => Craft::t('formie', 'Payment Amount'),
                'help' => Craft::t('formie', 'Provide an amount for the transaction. This can be either a fixed value, or derived from a field.'),
                'children' => [
                    [
                        '$el' => 'div',
                        'attrs' => [
                            'class' => 'flex',
                        ],
                        'children' => [
                            SchemaHelper::selectField([
                                'name' => 'amountType',
                                'options' => [
                                    ['label' => Craft::t('formie', 'Fixed Value'), 'value' => Payment::VALUE_TYPE_FIXED],
                                    ['label' => Craft::t('formie', 'Dynamic Value'), 'value' => Payment::VALUE_TYPE_DYNAMIC],
                                ],
                            ]),
                            SchemaHelper::numberField([
                                'name' => 'amountFixed',
                                'size' => 6,
                                'if' => '$get(amountType).value == ' . Payment::VALUE_TYPE_FIXED,
                            ]),
                            SchemaHelper::fieldSelectField([
                                'name' => 'amountVariable',
                                'fieldTypes' => [
                                    formfields\Calculations::class,
                                    formfields\Dropdown::class,
                                    formfields\Hidden::class,
                                    formfields\Number::class,
                                    formfields\Radio::class,
                                    formfields\SingleLineText::class,
                                ],
                                'if' => '$get(amountType).value == ' . Payment::VALUE_TYPE_DYNAMIC,
                            ]),
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function defineSettingsSchema(): array
    {
        return [
            [
                '$formkit' => 'staticTable',
                'label' => Craft::t('formie', 'Billing Details'),
                'help' => Craft::t('formie', 'Whether to send billing details alongside the payment.'),
                'name' => 'billingDetails',
                'columns' => [
                    'heading' => [
                        'type' => 'heading',
                        'heading' => Craft::t('formie', 'Billing Info'),
                        'class' => 'heading-cell thin',
                    ],
                    'value' => [
                        'type' => 'fieldSelect',
                        'label' => Craft::t('formie', 'Field'),
                        'class' => 'select-cell',
                    ],
                ],
                'rows' => [
                    'billingName' => [
                        'heading' => Craft::t('formie', 'Billing Name'),
                        'value' => '',
                    ],
                    'billingEmail' => [
                        'heading' => Craft::t('formie', 'Billing Email'),
                        'value' => '',
                    ],
                    'billingAddress' => [
                        'heading' => Craft::t('formie', 'Billing Address'),
                        'value' => '',
                    ],
                ],
            ],
        ];
    }

    public function getFrontEndSubfields($field, $context): array
    {
        $subFields = [];

        $rowConfigs = [
            [
                [
                    'type' => SingleLineText::class,
                    'name' => Craft::t('formie', 'Cardholder Name'),
                    'required' => true,
                    'inputAttributes' => [
                        [
                            'label' => 'data-opayo-card',
                            'value' => 'cardholder-name',
                        ],
                        [
                            'label' => 'name',
                            'value' => false,
                        ],
                    ],
                ],
            ],
            [
                [
                    'type' => SingleLineText::class,
                    'name' => Craft::t('formie', 'Card Number'),
                    'required' => true,
                    'placeholder' => '•••• •••• •••• ••••',
                    'inputAttributes' => [
                        [
                            'label' => 'data-opayo-card',
                            'value' => 'card-number',
                        ],
                        [
                            'label' => 'name',
                            'value' => false,
                        ],
                    ],
                ],
                [
                    'type' => SingleLineText::class,
                    'name' => Craft::t('formie', 'Expiry'),
                    'required' => true,
                    'placeholder' => 'MMYY',
                    'inputAttributes' => [
                        [
                            'label' => 'data-opayo-card',
                            'value' => 'expiry-date',
                        ],
                        [
                            'label' => 'name',
                            'value' => false,
                        ],
                    ],
                ],
                [
                    'type' => SingleLineText::class,
                    'name' => Craft::t('formie', 'CVC'),
                    'required' => true,
                    'placeholder' => '•••',
                    'inputAttributes' => [
                        [
                            'label' => 'data-opayo-card',
                            'value' => 'security-code',
                        ],
                        [
                            'label' => 'name',
                            'value' => false,
                        ],
                    ],
                ],
            ],
        ];

        foreach ($rowConfigs as $key => $rowConfig) {
            foreach ($rowConfig as $config) {
                $subField = Component::createComponent($config, FormFieldInterface::class);

                // Ensure we set the parent field instance to handle the nested nature of subfields
                $subField->setParentField($field);

                $subFields[$key][] = $subField;
            }
        }

        $event = new ModifyFrontEndSubfieldsEvent([
            'field' => $this,
            'rows' => $subFields,
        ]);

        Event::trigger(static::class, self::EVENT_MODIFY_FRONT_END_SUBFIELDS, $event);

        return $event->rows;
    }


    // Private Methods
    // =========================================================================

    private function _getPayload(string $opayoSessionKey, string $opayoTokenId, Submission $submission, int $amount, string $currency): array
    {
        $payload = [
            'transactionType' => 'Payment',
            'paymentMethod' => [
                'card' => [
                    'merchantSessionKey' => $opayoSessionKey,
                    'cardIdentifier' => $opayoTokenId,
                ],
            ],
            'vendorTxCode' => App::parseEnv($this->vendorName) . '-' . $submission->id . '-' . StringHelper::randomString(12),
            'amount' => $amount,
            'currency' => $currency,
            'description' => $submission->id,
            'apply3DSecure' => 'UseMSPSetting',
            'strongCustomerAuthentication' => $this->_getRequestDetail(),
        ];

        $billingName = $this->getFieldSetting('billingDetails.billingName');
        $billingAddress = $this->getFieldSetting('billingDetails.billingAddress');
        $billingEmail = $this->getFieldSetting('billingDetails.billingEmail');

        if ($billingName) {
            $integrationField = new IntegrationField();
            $integrationField->type = IntegrationField::TYPE_ARRAY;

            $fullName = $this->getMappedFieldValue($billingName, $submission, $integrationField);
        } else {
            // Values required by API
            $fullName = ['firstName' => 'Customer', 'lastName' => 'Name'];
        }

        $payload['customerFirstName'] = ArrayHelper::remove($fullName, 'firstName');
        $payload['customerLastName'] = ArrayHelper::remove($fullName, 'lastName');

        if ($billingAddress) {
            $integrationField = new IntegrationField();
            $integrationField->type = IntegrationField::TYPE_ARRAY;

            $address = $this->getMappedFieldValue($billingAddress, $submission, $integrationField);
        } else {
            // Values required by API
            $address = [
                'address1' => '407 St. John Street',
                'city' => 'London',
                'zip' => 'EC1V 4AB',
                'country' => 'GB',
            ];
        }

        $payload['billingAddress']['address1'] = ArrayHelper::remove($address, 'address1');
        $payload['billingAddress']['city'] = ArrayHelper::remove($address, 'city');
        $payload['billingAddress']['postalCode'] = ArrayHelper::remove($address, 'zip');
        $payload['billingAddress']['country'] = ArrayHelper::remove($address, 'country');

        // Testing only
        // $payload['billingAddress']['address1'] = '88';
        // $payload['billingAddress']['postalCode'] = '412';

        return $payload;
    }

    private function _getRequestDetail(): array
    {
        $returnUrl = UrlHelper::siteUrl('formie/payment-webhooks/process-callback', ['handle' => $this->handle]);

        $data = [
            'website' => Craft::$app->getRequest()->getOrigin(),
            'notificationURL' => $returnUrl,
            'browserIP' => Craft::$app->getRequest()->getUserIP(),
            'browserAcceptHeader' => Craft::$app->getRequest()->getHeaders()->get('accept'),
            'browserJavascriptEnabled' => false,
            'browserJavaEnabled' => false,
            'browserLanguage' => Craft::$app->language,
            'browserColorDepth' => '16',
            'browserScreenHeight' => '768',
            'browserScreenWidth' => '1200',
            'browserTZ' => '+300',
            'browserUserAgent' => Craft::$app->getRequest()->getUserAgent(),
            'challengeWindowSize' => 'Small',
            'threeDSRequestorChallengeInd' => '02',
            'requestSCAExemption' => false,
            'transType' => 'GoodsAndServicePurchase',
            'threeDSRequestorDecReqInd' => 'N',
        ];

        return $data;
    }
}