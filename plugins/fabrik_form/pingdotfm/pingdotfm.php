<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Fabrik.form.pingdotfm
 * @copyright   Copyright (C) 2005 Fabrik. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

/*******************************************************************************
 *
 * This product uses the Ping.fm API but is not endorsed or certified by Ping.fm
 *
 ******************************************************************************/

// Check to ensure this file is included in Joomla!
defined('_JEXEC') or die();

// Require the abstract plugin class
require_once COM_FABRIK_FRONTEND . '/models/plugin-form.php';

/**
 * Form submission plugin: Update Ping.fm
 *
 * @package     Joomla.Plugin
 * @subpackage  Fabrik.form.pingdotfm
 * @since       3.0
 */

class plgFabrik_FormPingdotfm extends plgFabrik_Form
{

	/**
	 * @var max length of message
	 */
	var $max_msg_length = 140;

	/**
	 * Run right at the end of the form processing
	 * form needs to be set to record in database for this to hook to be called
	 *
	 * @param   object  $params      plugin params
	 * @param   object  &$formModel  form model
	 *
	 * @return	bool
	 */

	public function onAfterProcess($params, &$formModel)
	{
		return $this->_process($params, $formModel);
	}

	/**
	 * Process to Ping.fm
	 *
	 * @param   object  $params      plugin params
	 * @param   object  &$formModel  form model
	 *
	 * @return  bool
	 */

	private function _process($params, &$formModel)
	{
		$app = JFactory::getApplication();
		$this->formModel = $formModel;
		jimport('joomla.filesystem.file');
		$w = new FabrikWorker;
		$data = $this->getEmailData();
		if (!$this->shouldProcess('ping_condition', $data))
		{
			return;
		}
		$apiKeys = $this->_getKeys($params);
		include_once 'PHPingFM.php';
		$ping = new PHPingFM($apiKeys['dev'], $apiKeys['user']);

		// Validate Keys

		if ($ping->validate() === false)
		{
			JError::raiseNotice(500, JText::_('Ping.fm Error') . ": Invalid Key");
			return;
		}

		// Use Method field?
		$pingMethodFieldId = $params->get('ping_method_field', '');
		if ($pingMethodFieldId != '')
		{
			$elementModel = FabrikWorker::getPluginManager()->getElementPlugin($pingMethodFieldId);
			$element = $elementModel->getElement(true);
			$pingMethodField = $elementModel->getFullName(false, true, false);
			$method = $data[$pingMethodField];

			if (!in_array($method, array('status', 'blog', 'microblog')))
			{
				$method = $params->get('ping_method', 'status');
			}
		}
		else
		{
			$method = $params->get('ping_method', 'status');
		}
		// Title & Msg fields
		$pingTitleFieldId = $params->get('ping_title_field', '');
		$pingMsgFieldId = $params->get('ping_msg_field', '');

		// Title (optional)
		if ($pingTitleFieldId != '')
		{ // Use field

			$elementModel = FabrikWorker::getPluginManager()->getElementPlugin($pingTitleFieldId);
			$pingTitleField = $elementModel->getFullName(false, true, false);

			$title = $data[$pingTitleField];
		}
		else
		{ // Use template
			$title = $w->parseMessageForPlaceHolder($params->get('ping_title_tmpl'), $data);
		}
		// 'blog' method requires a title
		if ($method == 'blog' && trim($title) == '')
		{
			JError::raiseNotice(500, JText::_('Ping.fm Error') . ". The 'blog' posting method requires a title.");
			return;
		}

		// Check Services enabled in Ping.fm
		$myServices = $ping->services();
		$okServices = false;
		if (empty($myServices))
		{ // No service enabled
			JError::raiseNotice(500, JText::_('Ping.fm Error') . " You must enable at least one service in your Ping.fm account");
			return;
		}
		else
		{ // Verify at least one service accepts the selected method
			foreach ($myServices as $myService)
			{
				if (in_array($method, $myService['methods']))
				{
					$okServices = true;
				}
			}
		}

		if ($okServices === false)
		{
			JError::raiseNotice(500, JText::_('PLG_FORM_PING_FM_METHOD_NOT_SUPPORTED'));
			return;
		}

		// Body
		if ($pingMsgFieldId != '')
		{ // Use field
			$elementModel = FabrikWorker::getPluginManager()->getElementPlugin($pingMsgFieldId);
			$element = $elementModel->getElement(true);

			$pingMsgField = $elementModel->getFullName(false, true, false);

			$msg = $data[$pingMsgField];
		}
		else
		{ // Use template
			$msg = $w->parseMessageForPlaceHolder($params->get('ping_msg_tmpl'), $data);
		}

		// If file paths in body then add the site URL so Ping.fm makes
		// his things (shortening the URLs with length > 20 chars)
		preg_match_all('/(\/.[^ ]*\/[^\/| ]+)/', $msg, $matches);
		if (!empty($matches))
		{
			$i = 0;
			foreach ($matches as $match)
			{
				if ($i > 0)
				{ // Do not replace 2 times
					break;
				}
				$msg = str_replace($match[0], JURI::base() . $match[0], $msg);
				$i++;
			}
		}

		// Add link to record
		$viewURL = COM_FABRIK_LIVESITE . "index.php?option=com_fabrik&view=details&fabrik=" . $formModel->getId();
		if (JRequest::getVar('usekey'))
		{
			$viewURL .= "&usekey=" . JRequest::getVar('usekey');
		}
		$viewURL .= "&rowid=" . JRequest::getVar('rowid');

		$msg = JString::str_ireplace('{LINK}', $viewURL, $msg);

		// Post to Ping.fm
		if (!empty($msg))
		{

			if ($ping->post($method, $msg, $title))
			{
				if ($params->get('ping_show_success_msg') == true)
				{
					$app->enqueueMessage(JText::_('Successfully posted to Ping.fm'), 'message');
				}
			}
			else
			{
				JError::raiseNotice(500, JText::_('Ping.fm Error') . " Failed updating Ping.fm");
			}
		}
		return true;

	}

	/**
	 * Get API key
	 *
	 * @param   object  $params  plugin params
	 *
	 * @return  array
	 */

	private function _getKeys($params)
	{
		$apiKeys = array();

		$apiKeys['dev'] = '7f159e0835bf1e7166760dd3c3666439';
		$apiKeys['user'] = $params->get('ping_userapikey', '');

		return $apiKeys;
	}

}
