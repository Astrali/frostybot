<?php

    // Cache handler

    class config {

        // Get accounts from database
        public static function get($accountStub = null) {
            $db = new db();
            $result = $db->select('accounts');
            $accounts = [];
            foreach($result as $row) {
                $stub = strtolower($row->stub);
                $symbolmaps = $db->select('symbolmap',['exchange'=>$row->exchange]);
                $symbolmap = [];
                foreach($symbolmaps as $map) {
                    $symbolmap[$map->symbol] = $map->mapping;
                }
                $params = json_decode($row->parameters, true);
                $testnet = false;
                if (isset($params['urls']['api'])) {
                    if (is_array($params['urls']['api'])) {
                        foreach($params['urls']['api'] as $url) {
                            if (strpos($url, 'test') !== false) {
                                $testnet = true;
                            }
                        }
                    } else {
                        $url = $params['urls']['api'];
                        if (strpos($url, 'test') !== false) {
                            $testnet = true;
                        }
                    }
                }
                $account = [
                    'stub'          =>  strtolower($row->stub),
                    'description'   =>  $row->description,
                    'exchange'      =>  $row->exchange,
                    'parameters'    =>  $params,
                    'type'          =>  isset($params['options']['defaultType']) ? $params['options']['defaultType'] : '',
                    'subaccount'    =>  isset($params['headers']['FTX-SUBACCOUNT']) ? $params['headers']['FTX-SUBACCOUNT'] : '',
                    'testnet'       =>  $testnet,
                    'symbolmap'     =>  $symbolmap,
                ];
            
                if ((!is_null($accountStub)) && (strtolower($accountStub) == strtolower($stub))) {
                    unset($account['symbolmap']);
                    return $account;
                }
                $accounts[$stub] = $account;
            }
            return (!is_null($accountStub) ? [] : $accounts);
        }

        // Add or update accounts
        public static function manage($params) {
            $db = new db();
            $db->insertOrUpdate('exchanges',['exchange'=>'ftx','description'=>'FTX'],['exchange'=>'ftx']);
            $db->insertOrUpdate('exchanges',['exchange'=>'bitmex','description'=>'Bitmex'],['exchange'=>'bitmex']);
            $db->insertOrUpdate('exchanges',['exchange'=>'deribit','description'=>'Deribit'],['exchange'=>'deribit']);
            $db->insertOrUpdate('exchanges',['exchange'=>'binance','description'=>'Binance'],['exchange'=>'binance']);
            if ($params['stub_update'] == '__frostybot__') {
                return self::censor(self::get());
            }
            $stub = strtolower($params['stub_update']);
            if (!self::is_stub($stub)) {
                return false;
            }
            if (isset($params['delete'])) {
                return self::delete($stub);
            }
            $data = config::get($stub);
            foreach(['stub','description','exchange'] as $field) {
                $data[$field] = (isset($params[$field]) ? $params[$field] : (isset($data[$field]) ? $data[$field] : null));
            }
            foreach(['apiKey','secret','urls','headers'] as $field) {
                $data['parameters'][$field] = (isset($params[$field]) ? $params[$field] : (isset($data['parameters'][$field]) ? $data['parameters'][$field] : null));
            }
            if (isset($data['exchange'])) {
                if (!in_array(strtolower($data['exchange']), ['deribit','ftx','bitmex','binance'])) {
                    logger::error('Exchange not supported: '.$data['exchange'].' (only Deribit, Bitmex, Binance and FTX are supported)');
                }
            }
            if ((isset($params['testnet'])) && (isset($params['exchange']))) {
                $url = self::geturl($params['exchange'], $params['testnet']);
                if (!empty($url)) {
                    $data['parameters']['urls'] = ['api' => $url];
                }
            }
            if (($data['exchange'] == 'ftx') && (isset($params['subaccount']))) {
                if (!isset($data['parameters']['headers'])) {
                    $data['parameters']['headers'] = [];
                }
                $data['parameters']['headers']['FTX-SUBACCOUNT'] = $params['subaccount'];  // Required when using sub accounts on FTX
            }
            if ($data['exchange'] == 'binance') {
                if (isset($params['type'])) {
                    if (in_array(strtolower($params['type']), ['spot', 'future', 'futures', 'margin'])) {
                        if (strtolower($params['type']) == 'spot') {
                            logger::notice("Since a spot exchange does not have the concept of 'positions', positions are emulated from current balances of assets against USDT");
                        }
                        $data['parameters']['options'] = ['defaultType' => str_replace('futures','future',strtolower($params['type']))];
                    } else {
                        logger::error('Invalid type parameter. Should be "spot", "futures" or "margin"');
                    }
                } else {
                    $data['parameters']['options'] = ['defaultType' => 'future'];
                }
            } 
            if (is_null($data['parameters']['urls'])) {
                unset($data['parameters']['urls']);
            }
            if (is_null($data['parameters']['headers'])) {
                unset($data['parameters']['headers']);
            }
            return self::insertOrUpdate($data);
            
        }

        // Insert or update
        private static function insertOrUpdate($config) {
            $data = config::get($config['stub']);
            if (count($data) > 0) {
                return self::update($config);
            } else {
                return self::insert($config);
            }
        }

        // Insert a new config
        private static function insert($config) {
            $stub = $config['stub'];
            $config['parameters'] = json_encode($config['parameters']);
            logger::debug("Creating new config for stub: ".$stub);
            $db = new db();
            $db->insert('accounts',$config);
            return self::censor(self::get());
        }
    
        // Update a config
        private static function update($config) {
            $stub = $config['stub'];
            $config['parameters'] = json_encode($config['parameters']);
            logger::debug("Updating config for stub: ".$stub);
            $db = new db();
            $db->update('accounts',$config,['stub'=>strtolower($stub)]);
            return self::censor(self::get());
        }

        // Delete a config
        private static function delete($stub) {
            logger::debug("Deleting config for stub: ".$stub);
            $db = new db();
            $db->delete('accounts',['stub'=>strtolower($stub)]);
            return self::censor(self::get());
        }

        // Remove secrets from config output
        private static function censor($config) {
            $output = [];
            foreach($config as $key => $val) {
                if (isset($val['parameters']['apiKey'])) {
                    $val['parameters']['apiKey'] = str_repeat("*",10);
                }
                if (isset($val['parameters']['secret'])) {
                    $val['parameters']['secret'] = str_repeat("*",10);
                }
                $output[$key] = $val;
            }
            return $output;
        }

        // Import accounts
        public static function import($accounts) {
            $db = new db();
            foreach($accounts as $stub => $settings) {
                $data = [
                    'stub'          => strtolower($stub),
                    'description'   => $settings['description'],
                    'exchange'      => $settings['exchange'],
                    'parameters'    => json_encode($settings['parameters'], JSON_PRETTY_PRINT)
                ];
                $db->delete('accounts', ['stub' => strtolower($stub)]);
                if ($db->insert('accounts', $data)) {
                    logger::debug('Imported account settings into the database: '.strtolower($stub));
                } else {
                    logger::debug('Error importing account settings into the database: '.strtolower($stub));
                }
            }
        }

        // Get Exchange URLs
        public static function geturl($exchange, $testnet=false) {
            $exchange = "\\ccxt\\" .strtolower($exchange);
            $ccxt = new $exchange([]);
            $urls = $ccxt->urls;
            echo (string) $testnet.PHP_EOL;
            if ((string) $testnet == "true") {
                if (isset($urls['test'])) {
                    return $urls['test'];
                } else {
                    logger::error('You requested to use a testnet, but this exchange does not have one');
                }
            }
            return $urls['api'];
        }

        // Check that stub is valid
        private static function is_stub($stub) {
            if (preg_match('/^[a-zA-Z]+[a-zA-Z0-9._]+$/', $stub)) {
                return true;
            } else {
                logger::error('Invalid stub name ('.$stub.'). The account stub must be alphanumeric characters only.');
                return false;
            }
        }

    }


?>