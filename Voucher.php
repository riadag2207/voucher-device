<?php

class Voucher
{

    // show Description
    function description()
    {
        return [
            'title' => 'Voucher Hotspot Direct',
            'description' => 'Create Hotspot Voucher directly to Mikrotik without user registration. Works like Mikhmon voucher system - just generate and use immediately',
            'author' => 'ibnu maksum',
            'url' => [
                'Github' => 'https://github.com/hotspotbilling/phpnuxbill/',
                'Telegram' => 'https://t.me/phpnuxbill',
                'Donate' => 'https://paypal.me/ibnux'
            ]
        ];
    }

    // Add Customer to Mikrotik/Device - Langsung ke Mikrotik tanpa register
    function add_customer($customer, $plan)
    {
        global $config;
        if (!empty($plan['plan_expired'])) {
            $p = ORM::for_table('tbl_plans')->where('id', $plan['plan_expired'])->find_one();
            
            if (!$p) {
                r2(U . 'order', 'e', "Plan not found");
                return false;
            }
            
            if ($p['is_radius'] == '1') {
                $router_name = 'radius';
            } else {
                $router_name = $plan['routers'];
            }
            
            // Generate voucher code
            repeat:
            if ($config['voucher_format'] == 'numbers') {
                $code = generateUniqueNumericVouchers(1, 10)[0];
            } else {
                $code = strtoupper(substr(md5(time() . rand(10000, 99999)), 0, 10));
                if ($config['voucher_format'] == 'low') {
                    $code = strtolower($code);
                } else if ($config['voucher_format'] == 'rand') {
                    $code = Lang::randomUpLowCase($code);
                }
            }
            $code = 'VC' . $code;
            
            // Cek duplikasi
            if (ORM::for_table('tbl_voucher')->whereRaw("BINARY `code` = '$code'")->find_one()) {
                goto repeat;
            }
            
            // LANGSUNG TAMBAHKAN KE MIKROTIK
            if ($router_name != 'radius') {
                $result = $this->addToMikrotik($code, $p, $router_name);
                if (!$result['success']) {
                    r2(U . 'order', 'e', $result['message']);
                    return false;
                }
            }
            
            // Simpan record voucher untuk tracking saja
            $d = ORM::for_table('tbl_voucher')->create();
            $d->type = $p['type'];
            $d->routers = $router_name;
            $d->id_plan = $p['id'];
            $d->code = $code;
            $d->user = $code;
            $d->status = '0'; // 0 = ready to use
            $d->generated_by = isset($customer['id']) ? $customer['id'] : 'admin';
            $d->generated_date = date('Y-m-d H:i:s');
            $d->save();
            
            // Kirim notifikasi jika ada customer
            if (isset($customer['id']) && $customer['id'] > 0) {
                $v = ORM::for_table('tbl_customers_inbox')->create();
                $v->from = "System";
                $v->customer_id = $customer['id'];
                $v->subject = 'Voucher ' . $p['name_plan'] . ' Ready';
                $v->date_created = date('Y-m-d H:i:s');
                $v->body = nl2br("Dear $customer[fullname],\n\n" .
                    "Your Hotspot Voucher:\n\n" .
                    "Code: <b style='background:#000;color:#fff;padding:5px'>$code</b>\n" .
                    "Plan: $p[name_plan]\n" .
                    "Duration: $p[validity] $p[validity_unit]\n\n" .
                    "How to use:\n" .
                    "1. Connect to WiFi Hotspot\n" .
                    "2. Open browser (will redirect to login)\n" .
                    "3. Enter voucher code as Username and Password\n\n" .
                    "Best Regards");
                $v->save();
            }
            
            return $code;
        } else {
            r2(U . 'order', 'e', "Plan expired not set");
            return false;
        }
    }

    // Fungsi utama: Tambah user langsung ke Mikrotik
    private function addToMikrotik($username, $plan, $router_name)
    {
        try {
            $mikrotik = ORM::for_table('tbl_routers')->where('name', $router_name)->find_one();
            
            if (!$mikrotik) {
                return ['success' => false, 'message' => 'Router not found'];
            }
            
            // Koneksi ke Mikrotik
            $client = $this->connectMikrotik($mikrotik['ip_address'], $mikrotik['username'], 
                                             Password::_decrypt($mikrotik['password']));
            
            if (!$client) {
                return ['success' => false, 'message' => 'Cannot connect to Mikrotik'];
            }
            
            $password = $username; // Password sama dengan username
            
            if ($plan['type'] == 'Hotspot') {
                // Hitung limit uptime dalam format Mikrotik
                $limitUptime = $this->calculateUptime($plan['validity'], $plan['validity_unit']);
                
                // Tambah user ke Hotspot
                $client->write('/ip/hotspot/user/add', false);
                $client->write('=name=' . $username, false);
                $client->write('=password=' . $password, false);
                $client->write('=profile=' . $plan['name_plan'], false);
                $client->write('=limit-uptime=' . $limitUptime, false);
                $client->write('=comment=Voucher-Generated-' . date('Y-m-d'), true);
                $response = $client->read();
                
                // Cek apakah berhasil
                if (isset($response['!trap'])) {
                    return ['success' => false, 'message' => 'Failed to add to Mikrotik: ' . $response['!trap'][0]['message']];
                }
                
            } else if ($plan['type'] == 'PPPOE') {
                // Tambah user ke PPPoE Secret
                $client->write('/ppp/secret/add', false);
                $client->write('=name=' . $username, false);
                $client->write('=password=' . $password, false);
                $client->write('=profile=' . $plan['name_plan'], false);
                $client->write('=service=pppoe', false);
                $client->write('=comment=Voucher-Generated-' . date('Y-m-d'), true);
                $response = $client->read();
                
                if (isset($response['!trap'])) {
                    return ['success' => false, 'message' => 'Failed to add to Mikrotik: ' . $response['!trap'][0]['message']];
                }
            }
            
            return ['success' => true, 'message' => 'User added to Mikrotik successfully'];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    // Koneksi ke Mikrotik menggunakan RouterOS API
    private function connectMikrotik($ip, $user, $pass)
    {
        try {
            $client = new RouterosAPI();
            $client->debug = false;
            
            if ($client->connect($ip, $user, $pass)) {
                return $client;
            }
            return false;
        } catch (Exception $e) {
            return false;
        }
    }

    // Hitung uptime limit untuk Mikrotik
    private function calculateUptime($validity, $unit)
    {
        switch ($unit) {
            case 'Hrs':
                return $validity . 'h';
            case 'Days':
                return ($validity * 24) . 'h';
            case 'Months':
                return ($validity * 30 * 24) . 'h';
            default:
                return '24h';
        }
    }

    // Remove Customer dari Mikrotik
    function remove_customer($customer, $plan)
    {
        if (!empty($plan['routers']) && $plan['routers'] != 'radius') {
            $this->removeFromMikrotik($customer['username'], $plan['type'], $plan['routers']);
        }
    }

    // Hapus user dari Mikrotik
    private function removeFromMikrotik($username, $type, $router_name)
    {
        try {
            $mikrotik = ORM::for_table('tbl_routers')->where('name', $router_name)->find_one();
            if (!$mikrotik) return false;
            
            $client = $this->connectMikrotik($mikrotik['ip_address'], $mikrotik['username'], 
                                             Password::_decrypt($mikrotik['password']));
            if (!$client) return false;
            
            if ($type == 'Hotspot') {
                // Cari user di hotspot
                $client->write('/ip/hotspot/user/print', false);
                $client->write('?name=' . $username, true);
                $users = $client->read();
                
                if (isset($users[0]['.id'])) {
                    $client->write('/ip/hotspot/user/remove', false);
                    $client->write('=.id=' . $users[0]['.id'], true);
                    $client->read();
                }
            } else if ($type == 'PPPOE') {
                // Cari secret di pppoe
                $client->write('/ppp/secret/print', false);
                $client->write('?name=' . $username, true);
                $secrets = $client->read();
                
                if (isset($secrets[0]['.id'])) {
                    $client->write('/ppp/secret/remove', false);
                    $client->write('=.id=' . $secrets[0]['.id'], true);
                    $client->read();
                }
            }
            
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    // Fungsi untuk generate banyak voucher sekaligus
    function generate_bulk_vouchers($plan_id, $quantity, $router_name = '')
    {
        global $config;
        $p = ORM::for_table('tbl_plans')->where('id', $plan_id)->find_one();
        
        if (!$p) {
            return ['success' => false, 'message' => 'Plan not found'];
        }

        if ($p['is_radius'] == '1') {
            return ['success' => false, 'message' => 'RADIUS not supported yet'];
        }
        
        if (empty($router_name)) {
            $router_name = $p['routers'];
        }

        $vouchers = [];
        $failed = 0;
        
        for ($i = 0; $i < $quantity; $i++) {
            repeat:
            if ($config['voucher_format'] == 'numbers') {
                $code = generateUniqueNumericVouchers(1, 10)[0];
            } else {
                $code = strtoupper(substr(md5(time() . rand(10000, 99999) . $i), 0, 10));
                if ($config['voucher_format'] == 'low') {
                    $code = strtolower($code);
                } else if ($config['voucher_format'] == 'rand') {
                    $code = Lang::randomUpLowCase($code);
                }
            }
            $code = 'VC' . $code;
            
            if (ORM::for_table('tbl_voucher')->whereRaw("BINARY `code` = '$code'")->find_one()) {
                goto repeat;
            }
            
            // Tambahkan langsung ke Mikrotik
            $result = $this->addToMikrotik($code, $p, $router_name);
            
            if (!$result['success']) {
                $failed++;
                continue;
            }
            
            // Simpan record
            $d = ORM::for_table('tbl_voucher')->create();
            $d->type = $p['type'];
            $d->routers = $router_name;
            $d->id_plan = $p['id'];
            $d->code = $code;
            $d->user = $code;
            $d->status = '0';
            $d->generated_by = 'admin';
            $d->generated_date = date('Y-m-d H:i:s');
            $d->save();
            
            $vouchers[] = [
                'code' => $code,
                'plan' => $p['name_plan'],
                'duration' => $p['validity'] . ' ' . $p['validity_unit'],
                'type' => $p['type']
            ];
            
            usleep(100000); // 0.1 detik delay
        }

        return [
            'success' => true, 
            'vouchers' => $vouchers, 
            'count' => count($vouchers),
            'failed' => $failed,
            'total' => $quantity
        ];
    }

    // Fungsi-fungsi lain (tidak digunakan untuk voucher direct)
    public function change_username($from, $to) { }
    function add_plan($plan) { }
    function update_plan($old_name, $plan) { }
    function remove_plan($plan) { }
    function online_customer($customer, $router_name) { return false; }
    function connect_customer($customer, $ip, $mac_address, $router_name) { }
    function disconnect_customer($customer, $router_name) { }
}
