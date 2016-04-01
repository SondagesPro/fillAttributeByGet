<?php
/**
 * Fill the attribute via GET request when register
 *
 * @author Denis Chenu <denis@sondages.pro>
 * @copyright 2016 ValueMatch <http://valuematch.net>
 * @copyright 2016 Denis Chenu <http://www.sondages.pro>
 * @license GPL v3
 * @version 0.0.2
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 */

class fillAttributeByGet extends PluginBase
{
    protected $storage = 'DbStorage';
    static protected $description = 'Allow prefilling attribute value by GET parameters with registration.';
    static protected $name = 'fillAttributeByGet';

    private $sMessage;
    /**
    * Add function to be used in beforeQuestionRender event
    */
    public function init()
    {
        $this->subscribe('beforeRegister','stateGetValue');
        $this->subscribe('beforeTokenEmail','beforeTokenEmail');
    }

    public function stateGetValue()
    {
        if(!Yii::app()->request->getIsPostRequest())
        {
            $aSurveyInfo=getSurveyInfo($this->event->get("surveyid"),$this->event->get("lang"));
            $GetParams=array();

            foreach ($aSurveyInfo['attributedescriptions'] as $field => $attribute)
            {
                if(empty($attribute['show_register']) || $attribute['show_register'] != 'Y')
                {
                    if(App()->request->getQuery($attribute['description']))
                    {
                        $GetParams[$field]=sanitize_filename(trim(strval(App()->request->getQuery($attribute['description']))));
                    }
                }
            }
            if(!empty($GetParams))
            {
                Yii::app()->user->setState("fillAttributeByGet_GetParams_{$this->event->get("surveyid")}",$GetParams);
            }
            tracevar(Yii::app()->user->getState("fillAttributeByGet_GetParams_{$this->event->get("surveyid")}"));
            tracevar("fillAttributeByGet_GetParams_{$this->event->get("surveyid")}");

        }
    }

    public function beforeTokenEmail()
    {
        if($this->event->get('type')=='register')
        {

            $aToken=$this->event->get('token');
            $iSurveyId=intval(App()->request->getParam('surveyid',App()->request->getParam('sid')));// or inverse ?
            $GetParams=Yii::app()->user->getState("fillAttributeByGet_GetParams_{$iSurveyId}");

            // We surely have a token table, but validate ....
            if(!empty($GetParams) && tableExists('{{tokens_'.$iSurveyId.'}}'))
            {
                $aSurveyInfo=getSurveyInfo($iSurveyId,App()->language);

                $oToken=Token::model($iSurveyId)->findByPk($aToken['tid']);
                if($oToken)
                {
                    unset($aToken['tid']);
                    foreach($aToken as $key=>$value)
                    {
                        $oToken->$key=$value;
                    }
                    foreach($GetParams as $key=>$value)
                    {
                        $oToken->$key=$value;
                    }
                    if(!$oToken->save())
                    {
                        tracevar($oToken->getErrors());
                    }
                }
                else
                {
                    tracevar("token not found");
                }
                /* register resave token, then we must do the page ... */
                Yii::app()->user->setState("fillAttributeByGet_GetParams_{$this->event->get("surveyid")}","","");
                if (SendEmailMessage($this->event->get('body'), $this->event->get('subject'), $this->event->get('to'),  $this->event->get('from'),Yii::app()->getConfig('sitename'),(getEmailFormat($iSurveyId) == 'html'), $this->event->get('bounce')))
                {
                    if(empty($oToken->sent)){
                        $sMailMessage=gT("An email has been sent to the address you provided with access details for this survey. Please follow the link in that email to proceed.");
                    }else{
                        $sMailMessage=gT("The address you have entered is already registered. An email has been sent to this address with a link that gives you access to the survey.");
                    }
                    $today = dateShift(date("Y-m-d H:i:s"), "Y-m-d H:i", Yii::app()->getConfig('timeadjust'));
                    $oToken->sent=$today;
                    $oToken->save();
                    $this->sMessage="<div id='wrapper' class='message tokenmessage'>"
                        . "<p>".gT("Thank you for registering to participate in this survey.")."</p>\n"
                        . "<p>{$sMailMessage}</p>\n"
                        . "<p>".sprintf(gT("Survey administrator %s (%s)"),$aSurveyInfo['adminname'],$aSurveyInfo['adminemail'])."</p>"
                        . "</div>\n";
                }else{
                    $this->sMessage="<div id='wrapper' class='message tokenmessage'>"
                        . "<p>".gT("Thank you for registering to participate in this survey.")."</p>\n"
                        . "<p>".gT("You are registered but an error happened when trying to send the email - please contact the survey administrator.")."</p>\n"
                        . "<p>".sprintf(gT("Survey administrator %s (%s)"),$aSurveyInfo['adminname'],$aSurveyInfo['adminemail'])."</p>"
                        . "</div>\n";
                }
                $this->display($iSurveyId);

            }
        }
    }

    /**
    * Display needed public page
    * @param $iSurveyId
    */
    private function display($iSurveyId)
    {
        $sLanguage=Yii::app()->language;
        $aData['surveyid']=$surveyid=$iSurveyId;
        $aData['thissurvey']=getSurveyInfo($iSurveyId,$sLanguage);
        $sTemplate=getTemplatePath($aData['thissurvey']['template']);
        Yii::app()->setConfig('surveyID',$iSurveyId);//Needed for languagechanger
        $aData['sitename']=Yii::app()->getConfig('sitename');
        $aData['sMessage']=$this->sMessage;
        App()->controller->layout="bare";
        sendCacheHeaders();
        doHeader();
        $aViewData['sTemplate']=$sTemplate;
        if(!$this->sMessage){
            $aData['languagechanger']=makeLanguageChangerSurvey($sLanguage); // Only show language changer shown the form is shown, not after submission
            $aViewData['content']=self::getRegisterForm($iSurveyId);
        }else{
            $aViewData['content']=templatereplace($this->sMessage);
        }
        $aViewData['aData']=$aData;
        // Test if we come from index or from register
        App()->getClientScript()->registerPackage('jqueryui');
        App()->getClientScript()->registerPackage('jquery-touch-punch');
        App()->getClientScript()->registerScriptFile(Yii::app()->getConfig('generalscripts')."survey_runtime.js");
        useFirebug();
        App()->controller->render('/register/display',$aViewData);
        doFooter();
        App()->end();
    }
}
