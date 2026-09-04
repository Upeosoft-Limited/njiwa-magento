<?php
/**
 * What the two buttons on the settings page have in common.
 *
 * Both answer JSON to an admin who pressed a button, both read the settings of
 * the scope that admin is looking at, and both have to turn a refusal from
 * Njiwa into a sentence rather than a stack trace.
 *
 * It sits one directory above the two actions deliberately. The admin router
 * resolves a URL to a class named Controller\Adminhtml\<controller>\<action>,
 * so a class one level up cannot be reached by any URL. This is a base class
 * and never an endpoint.
 */

declare(strict_types=1);

namespace Upeo\Njiwa\Controller\Adminhtml;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Upeo\Njiwa\Exception\NjiwaException;
use Upeo\Njiwa\Model\Client;
use Upeo\Njiwa\Model\Config;

abstract class AbstractCheck extends Action implements HttpPostActionInterface
{
    /**
     * The resource declared in etc/acl.xml and named by the settings section.
     * Anybody allowed to change these settings is allowed to check them, and
     * nobody else is: Magento denies the action before execute() is reached.
     */
    public const ADMIN_RESOURCE = 'Upeo_Njiwa::config';

    /**
     * @var Client
     */
    protected $client;

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var JsonFactory
     */
    private $resultJsonFactory;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        Client $client,
        Config $config,
        StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->client = $client;
        $this->config = $config;
        $this->storeManager = $storeManager;
    }

    /**
     * The one shape both buttons answer in. The message is plain text and the
     * page renders it as text, so a label somebody typed into the Njiwa
     * console cannot bring markup onto a Magento settings page.
     */
    protected function reply(bool $success, string $message): Json
    {
        return $this->resultJsonFactory->create()->setData(
            [
                'success' => $success,
                'message' => $message,
            ]
        );
    }

    /**
     * The store whose settings the admin is actually looking at.
     *
     * Every setting in this section can be set per website and per store view,
     * so a check that always read the default scope would tell a merchant with
     * two websites that their key works when the key they are editing is a
     * different one. The switcher puts the scope in the URL; the buttons carry
     * it through.
     *
     * A website has no settings of its own to send with, so the check reads
     * the default store view of that website, which is what an order placed
     * there would use.
     */
    protected function scopeStoreId(): ?int
    {
        try {
            $store = $this->param('store');
            if ($store !== '') {
                return (int) $this->storeManager->getStore($store)->getId();
            }

            $website = $this->param('website');
            if ($website !== '') {
                $group = $this->storeManager->getGroup(
                    (int) $this->storeManager->getWebsite($website)->getDefaultGroupId()
                );

                return (int) $group->getDefaultStoreId();
            }
        } catch (NoSuchEntityException $e) {
            // The scope in the URL no longer exists, which happens when a
            // website is deleted in another tab. The default scope is the
            // honest answer rather than an error about something the admin
            // did not ask about.
            return null;
        }

        return null;
    }

    /**
     * The store name, used to say who a test message is from.
     */
    protected function shopName(?int $storeId): string
    {
        try {
            $store = $storeId === null
                ? $this->storeManager->getDefaultStoreView()
                : $this->storeManager->getStore($storeId);
        } catch (NoSuchEntityException $e) {
            return '';
        }

        if ($store instanceof Store) {
            return trim((string) $store->getFrontendName());
        }

        return $store === null ? '' : trim((string) $store->getName());
    }

    /**
     * Njiwa's refusals are already written for a person to read. The one
     * exception is the master switch, whose message is worded for a message
     * that was not sent, and neither button is sending an order message.
     */
    protected function explain(NjiwaException $e): string
    {
        if ($e->getErrorCode() === 'disabled') {
            return (string) __(
                'Send WhatsApp messages is set to No, so this module is not talking to Njiwa at all. Set it to Yes and save, then try again.'
            );
        }

        return $e->getMessage();
    }

    /**
     * One request parameter, as a string. A parameter can arrive as an array
     * if somebody hand-writes the request, and an array where a number is
     * expected must not reach the code that formats it.
     */
    protected function param(string $name): string
    {
        $value = $this->getRequest()->getParam($name);

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
