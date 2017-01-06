<?php
/*
 +--------------------------------------------------------------------+
 | CiviCRM version 4.7                                                |
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC (c) 2004-2016                                |
 +--------------------------------------------------------------------+
 | This file is a part of CiviCRM.                                    |
 |                                                                    |
 | CiviCRM is free software; you can copy, modify, and distribute it  |
 | under the terms of the GNU Affero General Public License           |
 | Version 3, 19 November 2007 and the CiviCRM Licensing Exception.   |
 |                                                                    |
 | CiviCRM is distributed in the hope that it will be useful, but     |
 | WITHOUT ANY WARRANTY; without even the implied warranty of         |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.               |
 | See the GNU Affero General Public License for more details.        |
 |                                                                    |
 | You should have received a copy of the GNU Affero General Public   |
 | License and the CiviCRM Licensing Exception along                  |
 | with this program; if not, contact CiviCRM LLC                     |
 | at info[AT]civicrm[DOT]org. If you have questions about the        |
 | GNU Affero General Public License or the licensing of CiviCRM,     |
 | see the CiviCRM license FAQ at http://civicrm.org/licensing        |
 +--------------------------------------------------------------------+
 */
namespace Civi\FlexMailer\Listener;

use Civi\FlexMailer\Event\AlterBatchEvent;
use Civi\FlexMailer\FlexMailerTask;

class BasicHeaders extends BaseListener {

  /**
   * Inject basic headers
   *
   * @param \Civi\FlexMailer\Event\AlterBatchEvent $e
   */
  public function onAlterBatch(AlterBatchEvent $e) {
    $mailing = $e->getMailing();

    foreach ($e->getTasks() as $task) {
      /** @var FlexMailerTask $task */
      list($verp) = $mailing->getVerpAndUrlsAndHeaders(
        $e->getJob()->id, $task->getEventQueueId(), $task->getHash(),
        $task->getAddress());

      $mailParams = array();
      $mailParams['List-Unsubscribe'] = "<mailto:{$verp['unsubscribe']}>";
      \CRM_Mailing_BAO_Mailing::addMessageIdHeader($mailParams, 'm', $e->getJob()->id, $task->getEventQueueId(), $task->getHash());
      $mailParams['Precedence'] = 'bulk';
      $mailParams['job_id'] = $e->getJob()->id;

      $mailParams['From'] = "\"{$mailing->from_name}\" <{$mailing->from_email}>";
      $mailParams['Reply-To'] = $verp['reply'];
      if ($mailing->replyto_email && ($mailParams['From'] != $mailing->replyto_email)) {
        $mailParams['Reply-To'] = $mailing->replyto_email;
      }

      $task->setMailParams(array_merge(
        $mailParams,
        $task->getMailParams()
      ));

    }
  }

}
