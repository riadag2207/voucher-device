<?php

class Voucher
{

    // show Description
    function description()
    {
        return [
            'title' => 'Voucher',
            'description' => 'This Device will Create Voucher for direct use without registration. Customer can use voucher code directly to connect to hotspot, similar to Mikhmon voucher system',
            'author' => 'ibnu maksum',
            'url' => [
                'Github' => 'https://github.com/hotspotbilling/phpnuxbill/',
                'Telegram' => 'https://t.me/phpnuxbill',
                'Donate' => 'https://paypal.me/ibnux'
            ]
        ];
    }

    // Add Customer to Mikrotik/Device - Modified for direct voucher usage
    function add_customer($customer, $plan)
    {
        global $config;
        if (!empty($plan['plan_expired'])) {
            $p = ORM::for_table('tbl_plans')->where('id', $plan['plan_expired'])->find_one();
            if ($p['is_radius'] == '1') {
                $router_name = 'radius';
            } else {
                $router_name = $plan['routers'];
            }
            if ($p) {
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
                // Format voucher: VC (Voucher Code) + timestamp untuk unique
                $code = 'VC' . $code;
                if (ORM::for_table('tbl_voucher')->whereRaw("BINARY `code` = '$code'")->find_one()) {
                    // if exist, generate another code
                    goto repeat;
                }
                
                // Tambahkan user ke Mikrotik/Router untuk Hotspot
                if ($router_name != 'radius') {
                    $this->createHotspotUser($code, $code, $p, $router_name);
                }
                
                $d = ORM::for_table('tbl_voucher')->create();
                $d->type = $p['type'];
                $d->routers = $router_name;
                $d->id_plan = $p['id'];
                $d->code = $code;
                // Set username sama dengan code untuk direct use
                $d->user = $code;
                $d->status = '0'; // 0 = belum dipakai
                $d->generated_by = isset($customer['id']) ? $customer['id'] : 'admin';
                $d->generated_date = date('Y-m-d H:i:s');
                if ($d->save()) {
                    // Jika ada customer (untuk notifikasi), kirim inbox
                    if (isset($customer['id']) && $customer['id'] > 0) {
                        $v = ORM::for_table('tbl_customers_inbox')->create();
                        $v->from = "System";
                        $v->customer_id = $customer['id'];
                        $v->subject = Lang::T('New Voucher for '.$p['name_plan'].' Created');
                        $v->date_created = date('Y-m-d H:i:s');
                        $v->body = nl2br("Dear $customer[fullname],\n\nYour Internet Voucher Code is : <span style=\"user-select: all; cursor: pointer; background-color: #000\">$code</span>\n" .
                            "Internet Plan: $p[name_plan]\n" .
                            "\nVoucher ini bisa langsung digunakan tanpa registrasi.\n" .
                            "Gunakan code ini sebagai username dan password saat login hotspot.\n\n" .
                            "Best Regards");
                        $v->save();
                    }
                    return $code;
                } else {
                    if (isset($customer['id'])) {
                        r2(U . 'order', 'e', "Voucher Failed to create, Please call admin");
                    }
                    return false;
                }
            } else {
                if (isset($customer['id'])) {
                    r2(U . 'order', 'e', "Plan not found");
                }
                return false;
            }
        } else {
            if (isset($customer['id'])) {
                r2(U . 'order', 'e', "Plan not found");
            }
            return false;
        }
    }

    // Fungsi untuk membuat user hotspot di Mikrotik
    private function createHotspotUser($username, $password, $plan, $router_name)
    {
        $mikrotik = Mikrotik::info($router_name);
        if (!$mikrotik) {
            return false;
        }

        $client = Mikrotik::getClient($mikrotik['ip_address'], $mikrotik['username'], $mikrotik['password']);
        
        if ($plan['type'] == 'Hotspot') {
            // Tambahkan user ke Hotspot User Profile
            $client->write('/ip/hotspot/user/add', false);
            $client->write('=name=' . $username, false);
            $client->write('=password=' . $password, false);
            $client->write('=profile=' . $plan['name_plan'], false);
            $client->write('=limit-uptime=' . $this->formatTime($plan['validity'], $plan['validity_unit']), false);
            $client->write('=comment=Voucher-' . date('Y-m-d'), true);
            $client->read();
        } else if ($plan['type'] == 'PPPOE') {
            // Tambahkan user ke PPPoE Secret
            $client->write('/ppp/secret/add', false);
            $client->write('=name=' . $username, false);
            $client->write('=password=' . $password, false);
            $client->write('=profile=' . $plan['name_plan'], false);
            $client->write('=comment=Voucher-' . date('Y-m-d'), true);
            $client->read();
        }
        
        return true;
    }

    // Format waktu untuk Mikrotik
    private function formatTime($validity, $unit)
    {
        switch ($unit) {
            case 'Hrs':
                return $validity . 'h';
            case 'Days':
                return ($validity * 24) . 'h';
            case 'Months':
                return ($validity * 30 * 24) . 'h';
            default:
                return $validity . 'h';
        }
    }

    // Remove Customer to Mikrotik/Device
    function remove_customer($customer, $plan)
    {
        // Ketika voucher digunakan dan expired, hapus dari router
        if (!empty($plan['routers']) && $plan['routers'] != 'radius') {
            $this->removeHotspotUser($customer['username'], $plan, $plan['routers']);
        }
    }

    // Fungsi untuk menghapus user dari Mikrotik
    private function removeHotspotUser($username, $plan, $router_name)
    {
        $mikrotik = Mikrotik::info($router_name);
        if (!$mikrotik) {
            return false;
        }

        $client = Mikrotik::getClient($mikrotik['ip_address'], $mikrotik['username'], $mikrotik['password']);
        
        if ($plan['type'] == 'Hotspot') {
            // Cari ID user
            $client->write('/ip/hotspot/user/print', false);
            $client->write('?name=' . $username, true);
            $users = $client->read();
            
            if (isset($users[0]['.id'])) {
                $client->write('/ip/hotspot/user/remove', false);
                $client->write('=.id=' . $users[0]['.id'], true);
                $client->read();
            }
        } else if ($plan['type'] == 'PPPOE') {
            // Cari ID secret
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
    }

    // customer change username
    public function change_username($from, $to)
    {
        // Untuk voucher sekali pakai, username tidak perlu diubah
    }

    // Add Plan to Mikrotik/Device
    function add_plan($plan)
    {
        // Plan sudah ada di database, tidak perlu action khusus
    }

    // Update Plan to Mikrotik/Device
    function update_plan($old_name, $plan)
    {
        // Plan sudah ada di database, tidak perlu action khusus
    }

    // Remove Plan from Mikrotik/Device
    function remove_plan($plan)
    {
        // Plan sudah ada di database, tidak perlu action khusus
    }

    // check if customer is online
    function online_customer($customer, $router_name)
    {
        // Bisa dicek melalui Mikrotik API atau RADIUS
        return false;
    }

    // make customer online
    function connect_customer($customer, $ip, $mac_address, $router_name)
    {
        // Koneksi akan di-handle oleh hotspot login
    }

    // make customer disconnect
    function disconnect_customer($customer, $router_name)
    {
        // Disconnect akan di-handle oleh sistem hotspot
    }

    // Function tambahan untuk generate multiple vouchers
    function generate_bulk_vouchers($plan_id, $quantity, $router_name = '')
    {
        global $config;
        $p = ORM::for_table('tbl_plans')->where('id', $plan_id)->find_one();
        
        if (!$p) {
            return ['success' => false, 'message' => 'Plan not found'];
        }

        if ($p['is_radius'] == '1') {
            $router_name = 'radius';
        } else if (empty($router_name)) {
            $router_name = $p['routers'];
        }

        $vouchers = [];
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

            // Tambahkan user ke Mikrotik/Router untuk Hotspot
            if ($router_name != 'radius') {
                $this->createHotspotUser($code, $code, $p, $router_name);
            }

            $d = ORM::for_table('tbl_voucher')->create();
            $d->type = $p['type'];
            $d->routers = $router_name;
            $d->id_plan = $p['id'];
            $d->code = $code;
            $d->user = $code;
            $d->status = '0';
            $d->generated_by = 'admin';
            $d->generated_date = date('Y-m-d H:i:s');
            
            if ($d->save()) {
                $vouchers[] = $code;
            }
            
            // Delay sedikit untuk avoid konflik
            usleep(100000); // 0.1 detik
        }

        return ['success' => true, 'vouchers' => $vouchers, 'count' => count($vouchers)];
    }
}
