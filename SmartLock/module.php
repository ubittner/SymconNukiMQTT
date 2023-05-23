<?php

/**
 * @project       SymconNukiMQTT/SmartLock
 * @file          module.php
 * @author        Ulrich Bittner
 * @copyright     2023 Ulrich Bittner
 * @license       https://creativecommons.org/licenses/by-nc-sa/4.0/ CC BY-NC-SA 4.0
 */

/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection PhpUnusedPrivateFieldInspection */
/** @noinspection PhpUnused */

declare(strict_types=1);

//SymOS on SymBox doesn't support fnmatch
if (!function_exists('fnmatch')) {
    function fnmatch($pattern, $string): bool
    {
        return boolval(preg_match('#^' . strtr(preg_quote($pattern, '#'), ['\*' => '.*', '\?' => '.']) . '$#i', $string));
    }
}

class NukiSmartLockMQTTAPI extends IPSModule
{
    ##### Constants
    private const MODULE_NAME = 'Nuki Smart Lock (MQTT API)';
    private const MODULE_PREFIX = 'NUKISLMQTT';
    private const MODULE_VERSION = '1.0-4, 23.05.2023';

    //MQTT Server (Splitter)
    private const NUKI_MQTT_SERVER_GUID = '{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}';

    //TX (Module -> Server)
    private const NUKI_MQTT_TX_GUID = '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}';

    //RX (Server -> Module)
    private const NUKI_MQTT_RX_GUID = '{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}';

    public function Create()
    {
        //Never delete this line!
        parent::Create();

        ##### Properties

        $this->RegisterPropertyString('MQTTTopic', '');
        $this->RegisterPropertyBoolean('UseDoorSensor', false);
        $this->RegisterPropertyBoolean('UseKeypad', false);
        $this->RegisterPropertyBoolean('UseProtocol', true);
        $this->RegisterPropertyInteger('ProtocolMaximumEntries', 5);

        ##### Variables

        //Lock action
        $profile = self::MODULE_PREFIX . '.' . $this->InstanceID . '.LockAction';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 1);
        }
        IPS_SetVariableProfileAssociation($profile, 1, $this->Translate('Unlock'), 'LockOpen', 0x0000FF);
        IPS_SetVariableProfileAssociation($profile, 2, $this->Translate('Lock'), 'LockClosed', 0xFF0000);
        IPS_SetVariableProfileAssociation($profile, 3, $this->Translate('Unlatch'), 'Door', 0x00FF00);
        IPS_SetVariableProfileAssociation($profile, 4, $this->Translate("Lock 'n' Go"), 'Lock', 0xFFFF00);
        IPS_SetVariableProfileAssociation($profile, 5, $this->Translate("Lock 'n' Go with unlatch"), 'Door', 0x00FF00);
        IPS_SetVariableProfileAssociation($profile, 6, $this->Translate('Full Lock 2x (720)'), 'LockClosed', 0xFF0000);
        $id = @$this->GetIDForIdent('LockAction');
        $this->RegisterVariableInteger('LockAction', $this->Translate('Lock Action'), $profile, 10);
        $this->EnableAction('LockAction');
        if (!$id) {
            $this->SetValue('LockAction', 1);
        }

        //Lock state
        $profile = self::MODULE_PREFIX . '.' . $this->InstanceID . '.LockState';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 1);
        }
        IPS_SetVariableProfileIcon($profile, '');
        IPS_SetVariableProfileAssociation($profile, 0, $this->Translate('Uncalibrated'), 'Warning', 0xFF0000);
        IPS_SetVariableProfileAssociation($profile, 1, $this->Translate('Locked'), 'LockClosed', 0xFF0000);
        IPS_SetVariableProfileAssociation($profile, 2, $this->Translate('Unlocking'), 'LockOpen', 0x0000FF);
        IPS_SetVariableProfileAssociation($profile, 3, $this->Translate('Unlocked'), 'LockOpen', 0x0000FF);
        IPS_SetVariableProfileAssociation($profile, 4, $this->Translate('Locking'), 'LockClosed', 0xFF0000);
        IPS_SetVariableProfileAssociation($profile, 5, $this->Translate('Unlatched'), 'Door', 0x00FF00);
        IPS_SetVariableProfileAssociation($profile, 6, $this->Translate("Unlocked (Lock 'n' Go)"), 'LockOpen', 0x0000FF);
        IPS_SetVariableProfileAssociation($profile, 7, $this->Translate('Unlatching'), 'Door', 0x00FF00);
        IPS_SetVariableProfileAssociation($profile, 253, $this->Translate('-'), 'Information', -1);
        IPS_SetVariableProfileAssociation($profile, 254, $this->Translate('Motor blocked'), 'Warning', 0xFF0000);
        IPS_SetVariableProfileAssociation($profile, 255, $this->Translate('Undefined'), 'Warning', -1);
        $id = @$this->GetIDForIdent('LockState');
        $this->RegisterVariableInteger('LockState', $this->Translate('Lock State'), $profile, 20);
        if (!$id) {
            $this->SetValue('LockState', 255);
        }

        //Battery critical
        $profile = self::MODULE_PREFIX . '.' . $this->InstanceID . '.BatteryCritical';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 0);
        }
        IPS_SetVariableProfileIcon($profile, 'Battery');
        IPS_SetVariableProfileAssociation($profile, false, 'OK', '', 0x00FF00);
        IPS_SetVariableProfileAssociation($profile, true, $this->Translate('Low Battery'), '', 0xFF0000);
        $this->RegisterVariableBoolean('BatteryCritical', $this->Translate('Battery'), $profile, 30);

        //Battery charge state
        $profile = self::MODULE_PREFIX . '.' . $this->InstanceID . '.BatteryChargeState';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 1);
        }
        IPS_SetVariableProfileValues($profile, 0, 100, 1);
        IPS_SetVariableProfileText($profile, '', '%');
        IPS_SetVariableProfileIcon($profile, 'Battery');
        $this->RegisterVariableInteger('BatteryChargeState', $this->Translate('Battery Charge State'), $profile, 40);

        //Battery charging
        $profile = self::MODULE_PREFIX . '.' . $this->InstanceID . '.BatteryCharging';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 0);
        }
        IPS_SetVariableProfileIcon($profile, 'Battery');
        IPS_SetVariableProfileAssociation($profile, false, $this->Translate('Inactive'), '', 0xFF0000);
        IPS_SetVariableProfileAssociation($profile, true, $this->Translate('Active'), '', 0x00FF00);
        $this->RegisterVariableBoolean('BatteryCharging', $this->Translate('Battery Charging'), $profile, 50);

        //Last update
        $id = @$this->GetIDForIdent('LastUpdate');
        $this->RegisterVariableString('LastUpdate', $this->Translate('Last Update'), '', 90);
        if (!$id) {
            IPS_SetIcon($this->GetIDForIdent('LastUpdate'), 'Clock');
        }

        ##### Attributes

        $this->RegisterAttributeInteger('DeviceType', 0);
        $this->RegisterAttributeString('Name', '');
        $this->RegisterAttributeString('Firmware', '');
        $this->RegisterAttributeInteger('Mode', 2);
        $this->RegisterAttributeString('RingActionTimestamp', '');
        $this->RegisterAttributeBoolean('ServerConnected', false);
        $this->RegisterAttributeString('Timestamp', '');
        $this->RegisterAttributeBoolean('Connected', false);
        $this->RegisterAttributeInteger('CommandResponse', 0);
        $this->RegisterAttributeString('LockActionEvent', '');
        $this->RegisterAttributeString('Protocol', '[]');

        ##### Splitter

        //Connect to parent MQTT Server (Splitter)
        $this->ConnectParent(self::NUKI_MQTT_SERVER_GUID);
    }

    public function ApplyChanges()
    {
        //Wait until IP-Symcon is started
        $this->RegisterMessage(0, IPS_KERNELSTARTED);

        //Never delete this line!
        parent::ApplyChanges();

        //Check kernel runlevel
        if (IPS_GetKernelRunlevel() != KR_READY) {
            return;
        }

        //Filter for ReceiveData
        $MQTTTopic = $this->ReadPropertyString('MQTTTopic');
        $this->SetReceiveDataFilter('.*' . $MQTTTopic . '.*');

        ##### Maintain variables

        //Door sensor
        if ($this->ReadPropertyBoolean('UseDoorSensor')) {

            //Door sensor state
            $profile = self::MODULE_PREFIX . '.' . $this->InstanceID . '.DoorSensorState';
            if (!IPS_VariableProfileExists($profile)) {
                IPS_CreateVariableProfile($profile, 1);
            }
            IPS_SetVariableProfileIcon($profile, '');
            IPS_SetVariableProfileAssociation($profile, 1, $this->Translate('Deactivated'), 'Warning', 0x0000FF);
            IPS_SetVariableProfileAssociation($profile, 2, $this->Translate('Door closed'), 'Door', 0x00FF00);
            IPS_SetVariableProfileAssociation($profile, 3, $this->Translate('Door opened'), 'Door', 0xFF0000);
            IPS_SetVariableProfileAssociation($profile, 4, $this->Translate('Door state unknown'), 'Warning', -1);
            IPS_SetVariableProfileAssociation($profile, 5, $this->Translate('Calibrating'), 'Gear', 0xFFFF00);
            IPS_SetVariableProfileAssociation($profile, 16, $this->Translate('Uncalibrated'), 'Gear', 0xFFFF00);
            IPS_SetVariableProfileAssociation($profile, 240, $this->Translate('Tampered'), 'Gear', 0xFFFF00);
            IPS_SetVariableProfileAssociation($profile, 255, $this->Translate('Unknown'), 'Warning', -1);
            $id = @$this->GetIDForIdent('DoorSensorState');
            $this->MaintainVariable('DoorSensorState', $this->Translate('Door Sensor State'), 1, $profile, 60, true);
            if (!$id) {
                $this->SetValue('DoorSensorState', 255);
            }

            //Door sensor battery critical
            $profile = self::MODULE_PREFIX . '.' . $this->InstanceID . '.DoorSensorBatteryCritical';
            if (!IPS_VariableProfileExists($profile)) {
                IPS_CreateVariableProfile($profile, 0);
            }
            IPS_SetVariableProfileIcon($profile, 'Battery');
            IPS_SetVariableProfileAssociation($profile, false, 'OK', '', 0x00FF00);
            IPS_SetVariableProfileAssociation($profile, true, $this->Translate('Low Battery'), '', 0xFF0000);
            $this->MaintainVariable('DoorSensorBatteryCritical', $this->Translate('Door Sensor Battery'), 0, $profile, 70, true);
        } else {
            $this->MaintainVariable('DoorSensorState', $this->Translate('Door Sensor State'), 1, '', 0, false);
            $this->UnregisterProfile('DoorSensorStates');
            $this->MaintainVariable('DoorSensorBatteryCritical', $this->Translate('Door Sensor Battery'), 0, '', 0, false);
            $this->UnregisterProfile('DoorSensorBatteryCritical');
        }

        //Keypad
        if ($this->ReadPropertyBoolean('UseKeypad')) {
            //Keypad battery critical
            $profile = self::MODULE_PREFIX . '.' . $this->InstanceID . '.KeypadBatteryCritical';
            if (!IPS_VariableProfileExists($profile)) {
                IPS_CreateVariableProfile($profile, 0);
            }
            IPS_SetVariableProfileIcon($profile, 'Battery');
            IPS_SetVariableProfileAssociation($profile, false, 'OK', '', 0x00FF00);
            IPS_SetVariableProfileAssociation($profile, true, $this->Translate('Low Battery'), '', 0xFF0000);
            $this->MaintainVariable('KeypadBatteryCritical', $this->Translate('Keypad Battery'), 0, $profile, 80, true);
        } else {
            $this->MaintainVariable('KeypadBatteryCritical', $this->Translate('Keypad Battery'), 0, '', 0, false);
            $this->UnregisterProfile('KeypadBatteryCritical');
        }

        //Protocol
        if ($this->ReadPropertyBoolean('UseProtocol')) {
            $id = @$this->GetIDForIdent('Protocol');
            $this->MaintainVariable('Protocol', $this->Translate('Protocol'), 3, 'HTMLBox', 100, true);
            if (!$id) {
                IPS_SetIcon($this->GetIDForIdent('Protocol'), 'Database');
            }
        } else {
            $this->MaintainVariable('Protocol', $this->Translate('Protocol'), 3, '', 0, false);
            $this->WriteAttributeString('Protocol', '[]');
        }
        $this->UpdateProtocol();
    }

    public function Destroy()
    {
        //Never delete this line!
        parent::Destroy();

        //Delete profiles
        $profiles = ['LockActions', 'LockStates', 'BatteryCritical', 'BatteryChargeState', 'BatteryCharging'];
        if (!empty($profiles)) {
            foreach ($profiles as $profile) {
                $profileName = self::MODULE_PREFIX . '.' . $this->InstanceID . '.' . $profile;
                $this->UnregisterProfile($profileName);
            }
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        $this->SendDebug(__FUNCTION__, $TimeStamp . ', SenderID: ' . $SenderID . ', Message: ' . $Message . ', Data: ' . print_r($Data, true), 0);
        if ($Message == IPS_KERNELSTARTED) {
            $this->KernelReady();
        }
    }

    public function GetConfigurationForm()
    {
        $data = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        //Module name
        $data['elements'][1]['caption'] = self::MODULE_NAME;
        //Version
        $data['elements'][2]['caption'] = 'Version: ' . self::MODULE_VERSION;
        return json_encode($data);
    }

    /**
     * @throws Exception
     */
    public function ReceiveData($JSONString)
    {
        if (($this->ReadPropertyString('MQTTTopic')) != '') {
            $this->SendDebug(__FUNCTION__, 'Incoming data: ' . $JSONString, 0);
            $buffer = json_decode($JSONString);
            $existingTopic = false;
            if (property_exists($buffer, 'Topic')) {
                $existingTopic = true;
                $topic = $buffer->Topic;
                $this->SendDebug(__FUNCTION__ . ' Topic', $topic, 0);
            }
            $existingPayload = false;
            if (property_exists($buffer, 'Payload')) {
                $existingPayload = true;
                $payload = $buffer->Payload;
                $this->SendDebug(__FUNCTION__ . ' Payload', $payload, 0);
            }
            if (isset($topic) && isset($payload)) {
                if ($existingTopic && $existingPayload) {
                    switch ($topic) {

                        ##### Published topics for device states

                        //Device type
                        /**
                         * Nuki device type (see Device Types).
                         * Beta: Only device Type 4 = Smart Lock 3.0 Pro is supported
                         *
                         * 0 =  Smart Lock
                         * 2 =  Opener
                         * 3 =  Smart Door
                         * 4 =  Smart Lock 3.0 (Pro)
                         */
                        case fnmatch('*/deviceType', $topic):
                            $this->SendDebug(__FUNCTION__, 'deviceType: ' . $payload, 0);
                            $this->WriteAttributeInteger('DeviceType', intval($payload));
                            break;

                        //Name
                        /**
                         * Name of the device.
                         */
                        case fnmatch('*/name', $topic):
                            $this->SendDebug(__FUNCTION__, 'name: ' . $payload, 0);
                            $this->WriteAttributeString('Name', strval($payload));
                            break;

                        //Firmware
                        /**
                         * Current firmware version of the device.
                         */
                        case fnmatch('*/firmware', $topic):
                            $this->SendDebug(__FUNCTION__, 'firmware: ' . $payload, 0);
                            $this->WriteAttributeString('Firmware', strval($payload));
                            break;

                        //Mode
                        /**
                         * ID of the lock mode (see Modes).
                         *
                         * Smart Lock:
                         * 2 =  door mode
                         *
                         * Opener:
                         * 2 =  door mode
                         * 3 =  continuous mode (Ring to Open permanently active)
                         */
                        case fnmatch('*/mode', $topic):
                            $this->SendDebug(__FUNCTION__, 'mode: ' . $payload, 0);
                            $this->WriteAttributeInteger('Mode', intval($payload));
                            break;

                        //State
                        /**
                         * ID of the lock state (see Lock States).
                         *
                         * Smart Lock:
                         * 0 =      uncalibrated
                         * 1 =      locked
                         * 2 =      unlocking
                         * 3 =      unlocked
                         * 4 =      locking
                         * 5 =      unlatched
                         * 6 =      unlocked (lock ‘n’ go)
                         * 7 =      unlatching
                         * 253 =    -
                         * 254 =    motor blocked
                         * 255 =    undefined
                         *
                         * Opener:
                         * 0 =      untrained
                         * 1 =      online
                         * 2 =      -
                         * 3 =      rto active
                         * 4 =      -
                         * 5 =      open
                         * 6 =      -
                         * 7 =      opening
                         * 253 =    boot run
                         * 254 =    -
                         * 255 =    undefined
                         */
                        case fnmatch('*/state', $topic):
                            $this->SendDebug(__FUNCTION__, 'state: ' . $payload, 0);
                            $this->SetValue('LockState', intval($payload));
                            break;

                        //Battery critical
                        /**
                         * Flag indicating if the batteries of the Nuki device are at critical level.
                         *
                         * false =  battery normal
                         * true =   battery critical
                         */
                        case fnmatch('*/batteryCritical', $topic):
                            $this->SendDebug(__FUNCTION__, 'batteryCritical: ' . $payload, 0);
                            $value = false;
                            if ($payload == 'true') {
                                $value = true;
                            }
                            $this->SetValue('BatteryCritical', $value);
                            break;

                        //Battery charge state
                        /**
                         * Value representing the current charge status in %.
                         */
                        case fnmatch('*/batteryChargeState', $topic):
                            $this->SendDebug(__FUNCTION__, 'batteryChargeState: ' . $payload, 0);
                            $this->SetValue('BatteryChargeState', intval($payload));
                            break;

                        //Battery charging
                        /**
                         * Flag indicating if the batteries of the Nuki device are charging at the moment.
                         *
                         * false =  inactive
                         * true =   active
                         */
                        case fnmatch('*/batteryCharging', $topic):
                            $this->SendDebug(__FUNCTION__, 'batteryCharging: ' . $payload, 0);
                            $value = false;
                            if ($payload == 'true') {
                                $value = true;
                            }
                            $this->SetValue('BatteryCharging', $value);
                            break;

                        //Keypad battery critical
                        /**
                         * Flag indicating if the batteries of the paired Nuki Keypad are at critical level.
                         *
                         * false =  battery normal
                         * true =   battery critical
                         */
                        case fnmatch('*/keypadBatteryCritical', $topic):
                            $this->SendDebug(__FUNCTION__, 'keypadBatteryCritical: ' . $payload, 0);
                            if ($this->ReadPropertyBoolean('UseKeypad')) {
                                $value = false;
                                if ($payload == 'true') {
                                    $value = true;
                                }
                                $this->SetValue('KeypadBatteryCritical', $value);
                            }
                            break;

                        //Door sensor state
                        /**
                         * ID of the door sensor state.
                         *
                         * 1 =      deactivated
                         * 2 =      door closed
                         * 3 =      door opened
                         * 4 =      door state unknown
                         * 5 =      calibrating
                         * 16 =     uncalibrated
                         * 240 =    tampered
                         * 255 =    unknown
                         */
                        case fnmatch('*/doorsensorState', $topic):
                            $this->SendDebug(__FUNCTION__, 'doorsensorState: ' . $payload, 0);
                            if ($this->ReadPropertyBoolean('UseDoorSensor')) {
                                $this->SetValue('DoorSensorState', intval($payload));
                            }
                            break;

                        //Door sensor battery critical
                        /**
                         * Flag indicating if the batteries of the paired Nuki Door Sensor are at critical level.
                         *
                         * false =  battery normal
                         * true =   battery critical
                         */
                        case fnmatch('*/doorsensorBatteryCritical', $topic):
                            $this->SendDebug(__FUNCTION__, 'doorsensorBatteryCritical: ' . $payload, 0);
                            if ($this->ReadPropertyBoolean('UseDoorSensor')) {
                                $value = false;
                                if ($payload == 'true') {
                                    $value = true;
                                }
                                $this->SetValue('DoorSensorBatteryCritical', $value);
                            }
                            break;

                        //Ring action timestamp
                        /**
                         * Timestamp of the last ring-action. Only for Nuki Opener.
                         */
                        case fnmatch('*/ringactionTimestamp', $topic):
                            $this->SendDebug(__FUNCTION__, 'ringactionTimestamp: ' . $payload, 0);
                            $this->WriteAttributeString('RingActionTimestamp', strval($payload));
                            break;

                        //Server connected
                        /**
                         * Connection state to the Nuki server.
                         *
                         * false =  disconnected
                         * true =   connected
                         */
                        case fnmatch('*/serverConnected', $topic):
                            $this->SendDebug(__FUNCTION__, 'serverConnected: ' . $payload, 0);
                            $value = false;
                            if ($payload == 'true') {
                                $value = true;
                            }
                            $this->WriteAttributeBoolean('ServerConnected', $value);
                            break;

                        //Timestamp
                        /**
                         * Timestamp of the retrieval of the last update.
                         */
                        case fnmatch('*/timestamp', $topic):
                            $this->SendDebug(__FUNCTION__, 'timestamp: ' . $payload, 0);
                            $this->WriteAttributeString('Timestamp', strval($payload));
                            $time = strtotime(strval($payload));
                            $this->SetValue('LastUpdate', strval(date('d.m.Y, H:i:s', $time)));
                            break;

                        //Connected
                        /**
                         * Indicates if the device is currently connected to the MQTT server or not.
                         * Uses “false” as the last will message, which will be set by the mqtt server automatically if the device disconnects.
                         *
                         * false =  disconnected
                         * true =   connected
                         */
                        case fnmatch('*/connected', $topic):
                            $this->SendDebug(__FUNCTION__, 'connected: ' . $payload, 0);
                            $value = false;
                            if ($payload == 'true') {
                                $value = true;
                            }
                            $this->WriteAttributeBoolean('Connected', $value);
                            break;

                        ##### Published and subscribed topics for device control

                        //Lock action
                        /**
                         * ID of the desired Lock Action. Only actions 1-6 are supported.
                         *
                         * 1 =  unlock
                         * 2 =  lock
                         * 3 =  unlatch
                         * 4 =  lock ‘n’ go
                         * 5 =  lock ‘n’ go with unlatch
                         * 6 =  full lock
                         */
                        case fnmatch('*/lockAction', $topic):
                            $this->SendDebug(__FUNCTION__, 'lockAction: ' . $payload, 0);
                            break;

                        //Lock
                        /**
                         * Set to “true” to execute the simple lock action “lock”.
                         */
                        case fnmatch('*/lock', $topic):
                            $this->SendDebug(__FUNCTION__, 'lock: ' . $payload, 0);
                            break;

                        //Unlock
                        /**
                         * Set to “true” to execute the simple lock action “unlock”.
                         */
                        case fnmatch('*/unlock', $topic):
                            $this->SendDebug(__FUNCTION__, 'unlock: ' . $payload, 0);
                            break;

                        //Command response
                        /**
                         * The Nuki device publishes to this topic the return code of the last command it executed:
                         *
                         * 0 = Success
                         * 1-255 = Error code as described in the BLE API.
                         *
                         * Note:
                         * Nuki devices can only process one command at a time.
                         * If several commands are sent in parallel the commandResponses might overlap.
                         */
                        case fnmatch('*/commandResponse', $topic):
                            $this->SendDebug(__FUNCTION__, 'commandResponse: ' . $payload, 0);
                            $this->WriteAttributeInteger('CommandResponse', intval($payload));
                            break;

                        //Lock action event
                        /**
                         * The Nuki device publishes to this topic a comma separated list whenever a lock action is about to be executed:
                         *
                         * (1)
                         * LockAction:
                         * 1 =  unlock
                         * 2 =  lock
                         * 3 =  unlatch
                         * 4 =  lock ‘n’ go
                         * 5 =  lock ‘n’ go with unlatch
                         * 6 =  full lock
                         * 80 = fob (without action)
                         * 90 = button (without action)
                         *
                         * (2)
                         * Trigger:
                         * 0 =      system / bluetooth command
                         * 1 =      (reserved)
                         * 2 =      button
                         * 3 =      automatic (e.g. time control)
                         * 6 =      auto lock
                         * 171 =    HomeKit
                         * 172 =    MQTT
                         *
                         * (3)
                         * Auth-ID: Auth-ID of the user
                         *
                         * (4)
                         * Code-ID: ID of the Keypad code, 0 = unknown
                         *
                         * (5)
                         * Auto-Unlock (0 or 1) or number of button presses (only button & fob actions) or
                         * Keypad source (0 = back key, 1 = code, 2 = fingerprint)
                         *
                         * Hint:
                         * Only lock actions that are attempted to be executed are reported.
                         * E.g. unsuccessful Keypad code entries or lock commands outside a time window are not published.
                         */
                        case fnmatch('*/lockActionEvent', $topic):
                            $this->SendDebug(__FUNCTION__, 'lockActionEvent: ' . $payload, 0);
                            $this->WriteAttributeString('LockActionEvent', strval($payload));
                            if ($this->ReadPropertyBoolean('UseProtocol')) {
                                $existingData = json_decode($this->ReadAttributeString('Protocol'), true);
                                $data = explode(',', $payload);
                                $newData = [
                                    'timestamp'  => date('d.m.Y H:i:s'),
                                    'lockAction' => $data[0],
                                    'trigger'    => $data[1],
                                    'authID'     => $data[2],
                                    'codeID'     => $data[3],
                                    'autoUnlock' => $data[4]];
                                array_unshift($existingData, $newData);
                                $this->WriteAttributeString('Protocol', json_encode($existingData));
                                $this->UpdateProtocol();
                            }
                            break;

                    }
                } else {
                    $this->SendDebug(__FUNCTION__, 'Topic or Payload is missing!', 0);
                }
            }
        }
    }

    #################### Request Action

    public function RequestAction($Ident, $Value)
    {
        if ($Ident == 'LockAction') {
            $this->SetLockAction($Value);
        }
    }

    #################### Public

    /**
     * Simple lock action.
     *
     * @return void
     * @throws Exception
     */
    public function Lock(): void
    {
        if ($this->HasActiveParent()) {
            $this->SetValue('LockAction', 2);
            $Data['DataID'] = self::NUKI_MQTT_TX_GUID;
            $Data['PacketType'] = 3;
            $Data['QualityOfService'] = 0;
            $Data['Retain'] = false;
            $Data['Topic'] = $this->ReadPropertyString('MQTTTopic') . '/lock';
            $Data['Payload'] = 'true';
            $DataJSON = json_encode($Data, JSON_UNESCAPED_SLASHES);
            $this->SendDebug(__FUNCTION__ . ' Topic', $Data['Topic'], 0);
            $this->SendDebug(__FUNCTION__ . ' Data', $DataJSON, 0);
            $this->SendDataToParent($DataJSON);
        } else {
            $this->SendDebug(__FUNCTION__, 'Abort, parent is inactive!', 0);
        }
    }

    /**
     * Simple unlock action.
     *
     * @return void
     * @throws Exception
     */
    public function UnLock(): void
    {
        if ($this->HasActiveParent()) {
            $this->SetValue('LockAction', 1);
            $Data['DataID'] = self::NUKI_MQTT_TX_GUID;
            $Data['PacketType'] = 3;
            $Data['QualityOfService'] = 0;
            $Data['Retain'] = false;
            $Data['Topic'] = $this->ReadPropertyString('MQTTTopic') . '/unlock';
            $Data['Payload'] = 'true';
            $DataJSON = json_encode($Data, JSON_UNESCAPED_SLASHES);
            $this->SendDebug(__FUNCTION__ . ' Topic', $Data['Topic'], 0);
            $this->SendDebug(__FUNCTION__ . ' Data', $DataJSON, 0);
            $this->SendDataToParent($DataJSON);
        } else {
            $this->SendDebug(__FUNCTION__, 'Abort, parent is inactive!', 0);
        }
    }

    /**
     * Sets the lock action.
     * Only actions 1-6 are supported.
     *
     * @param int $Action
     * 1 =  unlock
     * 2 =  lock
     * 3 =  unlatch
     * 4 =  lock ‘n’ go
     * 5 =  lock ‘n’ go with unlatch
     * 6 =  full lock
     * 80 = fob (without action)
     * 90 = button (without action)
     *
     * @return void
     * @throws Exception
     */
    public function SetLockAction(int $Action): void
    {
        $this->SendDebug(__FUNCTION__, 'Action: ' . $Action, 0);
        //Only actions 1-6 are supported
        if ($Action > 6) {
            $this->SendDebug(__FUNCTION__, 'Abort, value is not supported!', 0);
            return;
        }
        if ($this->HasActiveParent()) {
            $this->SetValue('LockAction', $Action);
            $Data['DataID'] = self::NUKI_MQTT_TX_GUID;
            $Data['PacketType'] = 3;
            $Data['QualityOfService'] = 0;
            $Data['Retain'] = false;
            $Data['Topic'] = $this->ReadPropertyString('MQTTTopic') . '/lockAction';
            $Data['Payload'] = strval($Action);
            $DataJSON = json_encode($Data, JSON_UNESCAPED_SLASHES);
            $this->SendDebug(__FUNCTION__ . ' Topic', $Data['Topic'], 0);
            $this->SendDebug(__FUNCTION__ . ' Data', $DataJSON, 0);
            $this->SendDataToParent($DataJSON);
        } else {
            $this->SendDebug(__FUNCTION__, 'Abort, parent is inactive!', 0);
        }
    }

    /**
     * Gets the stored attributes.
     * Maybe attributes needed in future versions.
     *
     * @return array
     * @throws Exception
     */
    public function GetAttributes(): array
    {
        $attributeValues['DeviceType'] = $this->ReadAttributeInteger('DeviceType');
        $attributeValues['Name'] = $this->ReadAttributeString('Name');
        $attributeValues['Firmware'] = $this->ReadAttributeString('Firmware');
        $attributeValues['Mode'] = $this->ReadAttributeInteger('Mode');
        $attributeValues['RingActionTimestamp'] = $this->ReadAttributeString('RingActionTimestamp');
        $attributeValues['ServerConnected'] = $this->ReadAttributeBoolean('ServerConnected');
        $attributeValues['Timestamp'] = $this->ReadAttributeString('Timestamp');
        $attributeValues['Connected'] = $this->ReadAttributeBoolean('Connected');
        $attributeValues['CommandResponse'] = $this->ReadAttributeInteger('CommandResponse');
        $attributeValues['LockActionEvent'] = $this->ReadAttributeString('LockActionEvent');
        return $attributeValues;
    }

    #################### Private

    private function KernelReady()
    {
        $this->ApplyChanges();
    }

    private function UnregisterProfile(string $Name): void
    {
        if (!IPS_VariableProfileExists($Name)) {
            return;
        }
        foreach (IPS_GetVariableList() as $VarID) {
            if (IPS_GetParent($VarID) == $this->InstanceID) {
                continue;
            }
            if (IPS_GetVariable($VarID)['VariableCustomProfile'] == $Name) {
                return;
            }
            if (IPS_GetVariable($VarID)['VariableProfile'] == $Name) {
                return;
            }
        }
        foreach (IPS_GetMediaListByType(MEDIATYPE_CHART) as $mediaID) {
            $content = json_decode(base64_decode(IPS_GetMediaContent($mediaID)), true);
            foreach ($content['axes'] as $axis) {
                if ($axis['profile' === $Name]) {
                    return;
                }
            }
        }
        IPS_DeleteVariableProfile($Name);
    }

    private function UpdateProtocol(): void
    {
        if (!$this->ReadPropertyBoolean('UseProtocol')) {
            $this->SendDebug(__FUNCTION__, 'Abort, protocol is not enabled!', 0);
            return;
        }
        //Clean up protocol
        $existingData = json_decode($this->ReadAttributeString('Protocol'), true);
        //Check maximum entries
        $maximumEntries = $this->ReadPropertyInteger('ProtocolMaximumEntries');
        foreach ($existingData as $key => $data) {
            if ($key >= $maximumEntries) {
                //Delete
                unset($existingData[$key]);
            }
        }
        $this->WriteAttributeString('Protocol', json_encode($existingData));
        //Header
        $string = "<table style='width: 100%; border-collapse: collapse;'><tr><td><b>" . $this->Translate('Date') . '</b></td> <td><b>' . $this->Translate('Lock Action') . '</b></td><td><b>' . $this->Translate('Trigger') . '</b></td><td><b>Auth-ID</b></td><td><b>Code-ID</b></td><td><b>' . $this->Translate('Auto Unlock') . '</b></td></tr>';
        //Rows
        foreach (json_decode($this->ReadAttributeString('Protocol'), true) as $data) {
            //Lock action
            switch ($data['lockAction']) {
                case 1:
                    $lockAction = $this->Translate('unlock');
                    break;

                case 2:
                    $lockAction = $this->Translate('lock');
                    break;

                case 3:
                    $lockAction = $this->Translate('unlatch');
                    break;

                case 4:
                    $lockAction = $this->Translate('lock ‘n’ go');
                    break;

                case 5:
                    $lockAction = $this->Translate('lock ‘n’ go with unlatch');
                    break;

                case 6:
                    $lockAction = $this->Translate('full lock');
                    break;

                case 80:
                    $lockAction = $this->Translate('fob (without action)');
                    break;

                case 90:
                    $lockAction = $this->Translate('button (without action)');
                    break;

                default:
                    $lockAction = '';

            }
            //Trigger
            switch ($data['trigger']) {
                case 0:
                    $trigger = $this->Translate('system / bluetooth command');
                    break;

                case 1:
                    $trigger = $this->Translate('(reserved)');
                    break;

                case 2:
                    $trigger = $this->Translate('button');
                    break;

                case 3:
                    $trigger = $this->Translate('automatic (e.g. time control)');
                    break;

                case 6:
                    $trigger = $this->Translate('auto lock');
                    break;

                case 171:
                    $trigger = $this->Translate('HomeKit');
                    break;

                case 172:
                    $trigger = $this->Translate('MQTT');
                    break;

                default:
                    $trigger = '';

            }
            //Auto unlock
            switch ($data['autoUnlock']) {
                case 0:
                    $autoUnlock = $this->Translate('back key');
                    break;

                case 1:
                    $autoUnlock = $this->Translate('code');
                    break;

                case 2:
                    $autoUnlock = $this->Translate('fingerprint');
                    break;

                default:
                    $autoUnlock = '';
            }
            $string .= '<tr><td>' . $data['timestamp'] . '</td><td>' . $lockAction . '</td><td>' . $trigger . '</td><td>' . $data['authID'] . '</td><td>' . $data['codeID'] . '</td><td>' . $autoUnlock . '</td></tr>';
        }
        //Table end
        $string .= '</table>';
        $this->SetValue('Protocol', $string);
    }
}