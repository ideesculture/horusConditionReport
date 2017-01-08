<?php
/* ----------------------------------------------------------------------
 * horusCondition ReportForCAPlugin.php :
 * ----------------------------------------------------------------------
 * CollectiveAccess (Open-source collections management software)
 * Plugin for Horus Condition Report.
 * ----------------------------------------------------------------------
 *
 * GNU GPL v3
 *
 * ----------------------------------------------------------------------
 *
 *  This plugin : Created by idéesculture, 2017.
 *  CollectiveAccess has been created by Whirl-i-Gig, god bless them :-)
 */
 	require_once(__CA_APP_DIR__.'/helpers/mailHelpers.php');
 	require_once(__CA_MODELS_DIR__.'/ca_lists.php');
 	require_once(__CA_LIB_DIR__.'/core/Logging/Eventlog.php');
 	require_once(__CA_LIB_DIR__.'/core/Db.php');
 	require_once(__CA_LIB_DIR__.'/ca/Utils/DataMigrationUtils.php');
 	
 	require_once(__CA_LIB_DIR__.'/core/Zend/Mail.php');
 	require_once(__CA_LIB_DIR__.'/core/Zend/Mail/Storage/Imap.php');

    require_once(__CA_APP_DIR__.'/plugins/horusConditionReport/lib/fCore.php');
    require_once(__CA_APP_DIR__.'/plugins/horusConditionReport/lib/fEmail.php');
    require_once(__CA_APP_DIR__.'/plugins/horusConditionReport/lib/fMailbox.php');

	class horusConditionReportPlugin extends BaseApplicationPlugin {
		# -------------------------------------------------------
		private $opo_config;
		private $opo_client_services_config;
		# -------------------------------------------------------
		public function __construct($ps_plugin_path) {
			$this->description = _t('Accepts submissions of condition reports via email.');
			parent::__construct();
			
			$this->opo_config = Configuration::load($ps_plugin_path.'/conf/horusConditionReport.conf');

        }
		# -------------------------------------------------------
		/**
		 * Override checkStatus() to return true - the horusConditionReportForCAPlugin plugin always initializes ok
		 */
		public function checkStatus() {
			return array(
				'description' => $this->getDescription(),
				'errors' => array(),
				'warnings' => array(),
				'available' => ((bool)$this->opo_config->get('enabled'))
			);
		}
		# -------------------------------------------------------
		/**
		 * Perform periodic tasks
		 *
		 * @return boolean true because otherwise it disables subsequent plugins
		 */
		public function hookPeriodicTask(&$pa_params) {
			$t_log = new Eventlog();
			$o_db = new Db();
			//$t_log->log(array('CODE' => 'ERR', 'MESSAGE' => _t('Could not authenticate to remote system %1', $vs_base_url), 'SOURCE' => 'traveloguePlugin->hookPeriodicTask'));

			// Get new email
				$pn_locale_id = $this->opo_config->get('locale_id');
			
				$vs_server = $this->opo_config->get('imap_server');
                $vs_port = $this->opo_config->get('imap_port');
				$vs_username = $this->opo_config->get('username');
				$vs_password = $this->opo_config->get('password');
				$va_valid_senders = $this->opo_config->get('valid_senders');
				$vs_ssl = $this->opo_config->get('ssl');
				
				if (!$vs_server) { return true; }
				if (!$vs_username) { return true; }

				$va_mail_config = array(
                    'host'     => $vs_server,
                    'user'     => $vs_username,
                    'password' => $vs_password,
                    'ssl'      => $vs_ssl);

                $imap = new fMailbox("imap", $vs_server, $vs_username, $vs_password, $port=$vs_port, $secure=true, $timeout=600);
                $va_messages=$imap->listMessages();
                //var_dump($va_messages);


				$va_mails_to_delete = array();
				foreach ($va_messages as $va_message) {
                    // Affecting user from his/her email address
                    $t_user = new ca_users();

                    // Get user by email address
                    if (preg_match('!<([^>]+)>!', $va_message["from"], $va_matches)) {	// extract raw address from "from" header
                        $vs_from = $va_matches[1];
                    }
                    if ($t_user->load(array('email' => $vs_from))) {
                        $AUTH_CURRENT_USER_ID = $vn_user_id = $t_user->getPrimaryKey();    // force libs to consider matched user as logged in; change log will reflect this name
                        $va_mails_to_delete[]=$va_message["uid"];

                        $va_mail_content = $imap->fetchMessage($va_message["uid"]);

                        // Extract title from subject line of email
                        $vs_subject = $va_mail_content["headers"]["subject"];
                        $vs_subject_parts = explode(" - ", $vs_subject);
                        $vs_idno = $vs_subject_parts[0];
                        $vs_subject = $vs_subject_parts[1];

                        $vs_from = $va_mail_content["headers"]["from"]["mailbox"]."@".$va_mail_content["headers"]["from"]["host"];
                        print "PROCESSING {$vs_subject} FROM {$vs_from}\n";

                        if(sizeof($va_mail_content["attachment"] == 1)) {
                            // We have only one attachment, continuing
                            $attachment = reset($va_mail_content["attachment"]);
                            $filename = preg_replace('/\s+/', '_', $attachment["filename"]);
                            $vs_file_path = caGetTempDirPath().'/'.$filename;
                            if (file_put_contents($vs_file_path, $attachment["data"])) {
                                print "ATTACHMENT ". $attachment["filename"]." downloaded to ".$vs_file_path."\n";

                                // Create record (ca_objects or ca_occurrences)
                                $record_table = $this->opo_config->get('record_table');
                                $t_record = new $record_table();
                                $t_record->setMode(ACCESS_WRITE);
                                $t_record->set('type_id', $this->opo_config->get('record_type'));
                                $t_record->set('idno', $vs_idno);
                                $t_record->set('access', $this->opo_config->get('default_access'));
                                $t_record->set('status', $this->opo_config->get('default_status'));
                                $t_record->insert();
                                DataMigrationUtils::postError($t_record, "While adding object", "horusConditionReportForCAPlugin"); // TODO: log this

                                $t_record->addLabel(
                                    array('name' => $vs_subject), $pn_locale_id, null, true
                                );
                                DataMigrationUtils::postError($t_record, "While adding label", "horusConditionReportForCAPlugin");  // TODO: log this

								//var_dump($vs_file_path);die();
                                $t_record->addRepresentation($vs_file_path, $this->opo_config->get('representation_type'), 1, $this->opo_config->get('default_status'), $this->opo_config->get('default_access'), true, null, array("type_id"=>$this->opo_config->get('relationship_type_id'), "original_filename"=>$filename));
                                DataMigrationUtils::postError($t_record, "While adding media", "horusConditionReportForCAPlugin");  // TODO: log this
                                
                                $t_linked_object = new ca_objects();
                                $t_linked_object->load(array('idno' => $vs_idno));
                                $vn_object_id = $t_linked_object->get('object_id') * 1 ;
                                if($vn_object_id) {
	                                $t_rel_type = new ca_relationship_types(); // create an instance
									$rel_type_id = $t_rel_type->getRelationshipTypeID('ca_objects_x_occurrences', 'isReferencedBy'); // get id for type_code 'artist' in relationship ca_objects_x_entities
	                                $t_linked_object->setMode(ACCESS_WRITE);
	                                $t_linked_object->addRelationship('ca_objects_x_occurrences',$t_record->get("occurrence_id"),$rel_type_id);
	                                $t_linked_object->update();
                                }
                                //$t_record->addRelationship('ca_objects',,"isReferencedBy");

                            } else {
                                print "Probleme writing file : ".$vs_file_path."\n";
                                print "Please check path and permissions\n";
                            }

                        }

                        // TODO : link with the targeted record, idno coming at the beginning of the subject
                    } else {
                        //print "Impossible de charger l'utilisateur : ".$vs_from."\n";
                        // Reject import if the user is not authorized
                        //return true;
                    }


                }
				// TODO : Remove from server
				foreach($va_mails_to_delete as $vn_mail_to_delete) {
					print "Deleting email [UID ".$vn_mail_to_delete."]...\n";
                    $imap->deleteMessages(array($vn_mail_to_delete));
                }
			return true;
		}
		# -------------------------------------------------------
		/**
		 * Get plugin user actions
		 */
		static public function getRoleActionList() {
			return array();
		}
		# -------------------------------------------------------
	}
?>