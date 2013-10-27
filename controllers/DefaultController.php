<?php

class DefaultController extends UsrController
{
	public function actionIndex()
	{
		$this->render('index');
	}

	/**
	 * Redirects user either to returnUrl or main page.
	 */ 
	protected function afterLogin()
	{
		$returnUrlParts = explode('/',Yii::app()->user->returnUrl);
		if(end($returnUrlParts)=='index.php'){
			$url = '/';
		}else{
			$url = Yii::app()->user->returnUrl;
		}
		$this->redirect($url);
	}

	public function actionLogin($scenario = null)
	{
		if (!Yii::app()->user->isGuest)
			$this->redirect(Yii::app()->user->returnUrl);

		$model = new LoginForm;
		if ($scenario !== null && in_array($scenario, array('reset', 'verifyOTP'))) {
			$model->scenario = $scenario;
		}

		if(isset($_POST['ajax']) && $_POST['ajax']==='login-form') {
			echo CActiveForm::validate($model);
			Yii::app()->end();
		}

		if(isset($_POST['LoginForm'])) {
			$model->setAttributes($_POST['LoginForm']);
			if($model->validate() && $model->login()) {
				$this->afterLogin();
			}
		}
		switch($model->scenario) {
		default: $view = 'login'; break;
		case 'reset': $view = 'reset'; break;
		case 'verifyOTP': $view = 'verifyOTP'; break;
		}
		$this->render($view, array('model'=>$model));
	}

	/**
	 * Logs out the current user and redirect to homepage.
	 */
	public function actionLogout()
	{
		Yii::app()->user->logout();
		$this->redirect(Yii::app()->homeUrl);
	}

	public function actionRecovery()
	{
		if (!$this->module->recoveryEnabled) {
			throw new CHttpException(403,Yii::t('UsrModule.usr', 'Password recovery has not been enabled.'));
		}
		if (!Yii::app()->user->isGuest)
			$this->redirect(Yii::app()->user->returnUrl);

		$model = new RecoveryForm;

		if(isset($_POST['ajax']) && $_POST['ajax']==='recovery-form') {
			echo CActiveForm::validate($model);
			Yii::app()->end();
		}

		if (isset($_GET['activationKey'])) {
			$model->scenario = 'reset';
			$model->setAttributes($_GET);
		}
		if(isset($_POST['RecoveryForm'])) {
			$model->setAttributes($_POST['RecoveryForm']);
			if ($model->activationKey !== null)
				$model->scenario = 'reset';
			if($model->validate()) {
				if ($model->scenario !== 'reset') {
					if ($this->sendEmail($model, 'recovery')) {
						Yii::app()->user->setFlash('success', Yii::t('UsrModule.usr', 'An email containing further instructions has been sent to email associated with specified user account.'));
					} else {
						Yii::app()->user->setFlash('error', Yii::t('UsrModule.usr', 'Failed to send an email.').' '.Yii::t('UsrModule.usr', 'Try again or contact the site administrator.'));
					}
				} else {
					$model->getIdentity()->verifyEmail();
					if ($model->login()) {
						$this->afterLogin();
					} else {
						Yii::app()->user->setFlash('error', Yii::t('UsrModule.usr', 'Failed to change password or log in using new password.'));
					}
				}
				$this->redirect(array('recovery'));
			}
		}
		$this->render('recovery',array('model'=>$model));
	}

	public function actionVerify()
	{
		$model = new RecoveryForm;
		$model->scenario = 'verify';
		if (!isset($_GET['activationKey'])) {
			throw new CHttpException(400,Yii::t('UsrModule.usr', 'Activation key is missing.'));
		}
		$model->setAttributes($_GET);
		if($model->validate() && $model->getIdentity()->verifyEmail()) {
			Yii::app()->user->setFlash('success', Yii::t('UsrModule.usr', 'Your email address has been successfully verified.'));
		} else {
			Yii::app()->user->setFlash('error', Yii::t('UsrModule.usr', 'Failed to verify your email address.'));
		}
		$this->redirect(array(Yii::app()->user->isGuest ? 'login' : 'profile'));
	}

	public function actionRegister()
	{
		if (!$this->module->registrationEnabled) {
			throw new CHttpException(403,Yii::t('UsrModule.usr', 'Registration has not been enabled.'));
		}
		if (!Yii::app()->user->isGuest)
			$this->redirect(array('profile'));

		$model = new ProfileForm;
		$model->scenario = 'register';

		if(isset($_POST['ajax']) && $_POST['ajax']==='profile-form') {
			echo CActiveForm::validate($model);
			Yii::app()->end();
		}
		if(isset($_POST['ProfileForm'])) {
			$model->setAttributes($_POST['ProfileForm']);
			if ($model->register()) {
				if ($this->module->requireVerifiedEmail) {
					if ($this->sendEmail($model, 'verify')) {
						Yii::app()->user->setFlash('success', Yii::t('UsrModule.usr', 'An email containing further instructions has been sent to provided email address.'));
					} else {
						Yii::app()->user->setFlash('error', Yii::t('UsrModule.usr', 'Failed to send an email.').' '.Yii::t('UsrModule.usr', 'Try again or contact the site administrator.'));
					}
				}
				if ($model->getIdentity()->isActive()) {
					if ($model->login()) {
						$this->afterLogin();
					} else {
						Yii::app()->user->setFlash('error', Yii::t('UsrModule.usr', 'Failed to log in.').' '.Yii::t('UsrModule.usr', 'Try again or contact the site administrator.'));
					}
				} else {
					if (!Yii::app()->user->hasFlash('success'))
						Yii::app()->user->setFlash('success', Yii::t('UsrModule.usr', 'Please wait for the account to be activated. A notification will be send to provided email address.'));
					$this->redirect(array('login'));
				}
			}
		}
		$this->render('updateProfile',array('model'=>$model));
	}

	public function actionProfile($update=false)
	{
		if (Yii::app()->user->isGuest)
			$this->redirect(array('login'));

		$model=new ProfileForm;
		$model->setAttributes($model->getIdentity()->getAttributes());

		if(isset($_POST['ajax']) && $_POST['ajax']==='recovery-form') {
			echo CActiveForm::validate($model);
			Yii::app()->end();
		}
		if(isset($_POST['ProfileForm'])) {
			$model->setAttributes($_POST['ProfileForm']);
			if($model->validate()) {
				$oldEmail = $model->getIdentity()->getEmail();
				if ($model->save() && $model->resetPassword()) {
					$flashIsSet = false;
					if ($this->module->requireVerifiedEmail && $oldEmail != $model->email) {
						if ($this->sendEmail($model, 'verify')) {
							Yii::app()->user->setFlash('success', Yii::t('UsrModule.usr', 'An email containing further instructions has been sent to provided email address.'));
							$flashIsSet = true;
						} else {
							Yii::app()->user->setFlash('error', Yii::t('UsrModule.usr', 'Failed to send an email.').' '.Yii::t('UsrModule.usr', 'Try again or contact the site administrator.'));
						}
					}
					if (!$flashIsSet)
						Yii::app()->user->setFlash('success', Yii::t('UsrModule.usr', 'Changes have been saved successfully.'));
					$this->redirect(array('profile'));
				} else {
					Yii::app()->user->setFlash('error', Yii::t('UsrModule.usr', 'Failed to update profile.').' '.Yii::t('UsrModule.usr', 'Try again or contact the site administrator.'));
				}
			}
		}
		if ($update) {
			$this->render('updateProfile',array('model'=>$model));
		} else {
			$this->render('viewProfile',array('model'=>$model));
		}
	}

	protected function displayOneTimePasswordSecret()
	{
		$model = new OneTimePasswordForm;
		$identity = $model->getIdentity();
		$secret = $identity->getOneTimePasswordSecret();
		/*
		if ($secret === null && $this->module->oneTimePasswordRequired) {
			$googleAuthenticator = $this->module->googleAuthenticator;
			$secret = $googleAuthenticator->generateSecret();
			$identity->setOneTimePasswordSecret($secret);
		}
		$hostInfo = Yii::app()->request->hostInfo;
		$url = $model->getUrl($identity->username, parse_url($hostInfo, PHP_URL_HOST), $secret);
		 */
		if ($secret === null) {
			$label = CHtml::link(Yii::t('UsrModule.usr', 'Enable'), array('toggleOneTimePassword'));
		} else {
			$label = CHtml::link(Yii::t('UsrModule.usr', 'Disable'), array('toggleOneTimePassword'));
			/*if ($this->module->oneTimePasswordMode === UsrModule::OTP_TIME) {
				$label .= '<br/>'.CHtml::image($url, Yii::t('UsrModule.usr', 'One Time Password Secret'));
			}*/
		}
		return $label;
	}

	public function actionToggleOneTimePassword()
	{
		if (Yii::app()->user->isGuest)
			$this->redirect(array('login'));
		if ($this->module->oneTimePasswordRequired)
			$this->redirect(array('profile'));

		$model = new OneTimePasswordForm;
		$identity = $model->getIdentity();

		if ($identity->getOneTimePasswordSecret() !== null) {
			$identity->setOneTimePasswordSecret(null);
			Yii::app()->request->cookies->remove(UsrModule::OTP_COOKIE);
			$this->redirect('profile');
			return;
		}

		$model->setMode($this->module->oneTimePasswordMode)->setAuthenticator($this->module->googleAuthenticator);

		// generate a secret and save it in session if it hasn't been done yet
		if (($secret=Yii::app()->session[UsrModule::OTP_SECRET_PREFIX.'newSecret']) === null) {
			$secret = Yii::app()->session[UsrModule::OTP_SECRET_PREFIX.'newSecret'] = $this->module->googleAuthenticator->generateSecret();

			$model->setSecret($secret);
			if ($this->module->oneTimePasswordMode === UsrModule::OTP_COUNTER) {
				$this->sendEmail($model, 'oneTimePassword');
			}
		}
		$model->setSecret($secret);

		if (isset($_POST['OneTimePasswordForm'])) {
			$model->setAttributes($_POST['OneTimePasswordForm']);
			if ($model->validate()) {
				// save secret
				$identity->setOneTimePasswordSecret($secret);
				Yii::app()->session[UsrModule::OTP_SECRET_PREFIX.'newSecret'] = null;
				// save current code as used
				$identity->setOneTimePassword($model->oneTimePassword, $this->module->oneTimePasswordMode === UsrModule::OTP_TIME ? floor(time() / 30) : $model->getPreviousCounter() + 1);
				$this->redirect('profile');
			}
		}
		if (YII_DEBUG) {
			$model->oneTimePassword = $this->module->googleAuthenticator->getCode($secret, $this->module->oneTimePasswordMode === UsrModule::OTP_TIME ? null : $model->getPreviousCounter());
		}

		if ($this->module->oneTimePasswordMode === UsrModule::OTP_TIME) {
			$hostInfo = Yii::app()->request->hostInfo;
			$url = $model->getUrl($identity->username, parse_url($hostInfo, PHP_URL_HOST), $secret);
		} else {
			$url = '';
		}

		$this->render('generateOTPSecret', array('model'=>$model, 'url'=>$url));
	}

	public function actionPassword()
	{
		$diceware = new Diceware;
		$password = $diceware->get_phrase($this->module->dicewareLength, $this->module->dicewareExtraDigit, $this->module->dicewareExtraChar);
		echo json_encode($password);
	}
}
