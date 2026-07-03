<?php

//default responses


const DEFAULT_200 = [
    'response_code' => 'default_200',
    'message' => 'successfully fetched data'
];

const DEFAULT_204 = [
    'response_code' => 'default_204',
    'message' => 'data not found'
];

const DEFAULT_400 = [
    'response_code' => 'default_400',
    'message' => 'invalid or missing data'
];

const DEFAULT_401 = [
    'response_code' => 'default_401',
    'message' => 'unauthorized'
];

const DEFAULT_403 = [
    'response_code' => 'default_403',
    'message' => 'access denied'
];
const DEFAULT_404 = [
    'response_code' => 'default_404',
    'message' => 'no resource found'
];

const DEFAULT_DELETE_200 = [
    'response_code' => 'default_delete_200',
    'message' => 'successfully deleted data'
];

const DEFAULT_FAIL_200 = [
    'response_code' => 'default_fail_200',
    'message' => 'something went wrong'
];


const DEFAULT_STORE_200 = [
    'response_code' => 'default_store_200',
    'message' => 'successfully added'
];

const DISPUTE_STORE_200 = [
    'response_code' => 'default_store_200',
    'message' => 'Your Report send successfully. kindly wait for admin approval'
];

const DEFAULT_UPDATE_200 = [
    'response_code' => 'default_update_200',
    'message' => 'successfully updated'
];

const DEFAULT_STATUS_UPDATE_200 = [
    'response_code' => 'default_status_update_200',
    'message' => 'successfully status updated'
];

const TOO_MANY_ATTEMPT_403 = [
    'response_code' => 'too_many_attempt_403',
    'message' => 'your api hit limit exceeded, try after a minute.'
];


const REGISTRATION_200 = [
    'response_code' => 'registration_200',
    'message' => 'successfully registered'
];

//auth module
const AUTH_LOGIN_200 = [
    'response_code' => 'auth_login_200',
    'message' => 'successfully logged in'
];

const AUTH_LOGOUT_200 = [
    'response_code' => 'auth_logout_200',
    'message' => 'successfully logged out'
];

const AUTH_LOGIN_401 = [
    'response_code' => 'auth_login_401',
    'message' => 'Invalid Credentials'
];

const AUTH_LOGIN_403 = [
    'response_code' => 'auth_login_403',
    'message' => 'wrong credentials'
];

const AUTH_BLOCK_LOGIN_403 = [
    'response_code' => 'auth_login_403',
    'message' => 'You have been blocked'
];

const AUTH_LOGIN_400 = [
    'response_code' => 'auth_login_400',
    'message' => 'Invalid data'
];

const AUTH_LOGIN_404 = [
    'response_code' => 'auth_login_404',
    'message' => 'User not found'
];

const GATEWAYS_DEFAULT_200 = [
    'response_code' => 'gateways_default_200',
    'message' => 'successfully loaded'
];

const GATEWAYS_DEFAULT_204 = [
    'response_code' => 'gateways_default_204',
    'message' => 'information not found'
];

const GATEWAYS_DEFAULT_400 = [
    'response_code' => 'gateways_default_400',
    'message' => 'invalid or missing information'
];

const GATEWAYS_DEFAULT_404 = [
    'response_code' => 'gateways_default_404',
    'message' => 'resource not found'
];

const GATEWAYS_DEFAULT_UPDATE_200 = [
    'response_code' => 'gateways_default_update_200',
    'message' => 'successfully updated'
];
