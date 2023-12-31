<?php

namespace VK\Actions;

use VK\Client\Actions\ActionInterface;
use VK\Client\VKApiRequest;
use VK\Exceptions\Api\VKApiPrettyCardsCardIsConnectedToPostException;
use VK\Exceptions\Api\VKApiPrettyCardsCardNotFoundException;
use VK\Exceptions\Api\VKApiPrettyCardsTooManyCardsException;
use VK\Exceptions\VKApiException;
use VK\Exceptions\VKClientException;

class PrettyCards implements ActionInterface
{
	/** @param VKApiRequest $request */
	private VKApiRequest $request;


	/**
	 * PrettyCards constructor.
	 * @param VKApiRequest $request
	 */
	public function __construct(VKApiRequest $request)
	{
		$this->request = $request;
	}


	/**
	 * @param string $access_token
	 * @param array $params
	 * - @var integer owner_id
	 * - @var string photo
	 * - @var string title
	 * - @var string link
	 * - @var string price
	 * - @var string price_old
	 * - @var string button
	 * @return mixed
	 * @throws VKClientException
	 * @throws VKApiException
	 * @throws VKApiPrettyCardsTooManyCardsException Too many cards
	 */
	public function create(string $access_token, array $params = [])
	{
		return $this->request->post('prettyCards.create', $access_token, $params);
	}


	/**
	 * @param string $access_token
	 * @param array $params
	 * - @var integer owner_id
	 * - @var integer card_id
	 * @return mixed
	 * @throws VKClientException
	 * @throws VKApiException
	 * @throws VKApiPrettyCardsCardNotFoundException Card not found
	 * @throws VKApiPrettyCardsCardIsConnectedToPostException Card is connected to post
	 */
	public function delete(string $access_token, array $params = [])
	{
		return $this->request->post('prettyCards.delete', $access_token, $params);
	}


	/**
	 * @param string $access_token
	 * @param array $params
	 * - @var integer owner_id
	 * - @var integer card_id
	 * - @var string photo
	 * - @var string title
	 * - @var string link
	 * - @var string price
	 * - @var string price_old
	 * - @var string button
	 * @return mixed
	 * @throws VKClientException
	 * @throws VKApiException
	 * @throws VKApiPrettyCardsCardNotFoundException Card not found
	 */
	public function edit(string $access_token, array $params = [])
	{
		return $this->request->post('prettyCards.edit', $access_token, $params);
	}


	/**
	 * @param string $access_token
	 * @param array $params
	 * - @var integer owner_id
	 * - @var integer offset
	 * - @var integer count
	 * @return mixed
	 * @throws VKClientException
	 * @throws VKApiException
	 */
	public function get(string $access_token, array $params = [])
	{
		return $this->request->post('prettyCards.get', $access_token, $params);
	}


	/**
	 * @param string $access_token
	 * @param array $params
	 * - @var integer owner_id
	 * - @var array[integer] card_ids
	 * @return mixed
	 * @throws VKClientException
	 * @throws VKApiException
	 */
	public function getById(string $access_token, array $params = [])
	{
		return $this->request->post('prettyCards.getById', $access_token, $params);
	}


	/**
	 * @param string $access_token
	 * @return mixed
	 * @throws VKClientException
	 * @throws VKApiException
	 */
	public function getUploadURL(string $access_token)
	{
		return $this->request->post('prettyCards.getUploadURL', $access_token);
	}
}

