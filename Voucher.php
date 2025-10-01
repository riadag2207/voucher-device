<?php

class Voucher
{

    // show Description
    function description()
    {
        return [
            'title' => 'Voucher Direct Use',
            'description' => 'This Device will Create Voucher with auto-register user. Customer can use voucher code directly to connect to hotspot without manual registration, similar to Mikhmon voucher system',
            'author' => 'ibnu maksum',
            'url' => [
                'Github' => 'https://github.com/hotspotbilling/phpnuxbill/',
                'Telegram' => 'https://t.me/phpnuxbill',
                'Donate' => 'https://paypal.me/ibnux'
            ]
        ];
    }

    // Add Customer to Mikrotik/Device - Modified dengan auto-register user
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
                $code = 'VC' . $code;
                if (ORM::for_table('tbl_voucher')->whereRaw("BINARY `code` = '$code'")->find_one()) {
                    goto repeat;
                }
                
                // STEP 1: Auto-create dummy customer untuk voucher ini
                $dummy_customer = $this->createDummyCustomer($code);
                
                if (!$dummy_customer) {
                    if (isset($customer['id'])) {
                        r2(U . 'order', 'e', "Failed to create voucher user");
                    }
                    return false;
                }
                
                // STEP 2: Aktivasi plan untuk user dummy ini
                $activated = $this->activateUserPlan($dummy_customer['id'], $p, $router_name);
                
                if (!$activated) {
                    // Hapus dummy customer jika aktivasi gagal
                    ORM::for_table('tbl_customers')->where('id', $dummy_customer['id'])->delete_many();
                    if (isset($customer['id'])) {
                        r2(U . 'order', 'e', "Failed to activate voucher plan");
                    }
                    return false;
                }
                
                // STEP 3: Simpan voucher code untuk tracking
                $d = ORM::for_table('tbl_voucher')->create();
                $d->type = $p['type'];
                $d->routers = $router_name;
                $d->id_plan = $p['id'];
                $d->code = $code;
                $d->user = $code; // username sama dengan code
                $d->status = '1'; // 1 = sudah dipakai (karena sudah di-assign)
                $d->generated_by = isset($customer['id']) ? $customer['id'] : 'admin';
                $d->generated_date = date('Y-m-d H:i:s');
                $d->save();
                
                // Notifikasi ke customer asli (jika ada)
                if (isset($customer['id']) && $customer['id'] > 0) {
                    $v = ORM::for_table('tbl_customers_inbox')->create();
                    $v->from = "System";
                    $v->customer_id = $customer['id'];
                    $v->subject = Lang::T('New Voucher for '.$p['name_plan'].' Created');
                    $v->date_created = date('Y-m-d H:i:s');
                    $v->body = nl2br("Dear $customer[fullname],\n\nYour Internet Voucher Code is : <span style=\"user-select: all; cursor: pointer; background-color: #000\">$code</span>\n" .
                        "Internet Plan: $p[name_plan]\n" .
                        "Validity: $p[validity] $p[validity_unit]\n" .
                        "\nVoucher ini bisa langsung digunakan untuk login hotspot.\n" .
                        "Username: $code\n" .
                        "Password: $code\n\n" .
                        "Best Regards");
                    $v->save();
                }
                
                return $code;
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

    // Fungsi untuk membuat dummy customer otomatis
    private function createDummyCustomer($username)
    {
        // Cek apakah username sudah ada
        $existing = ORM::for_table('tbl_customers')->where('username', $username)->find_one();
        if ($existing) {
            return false; // Username sudah ada
        }
        
        $c = ORM::for_table('tbl_customers')->create();
        $c->username = $username;
        $c->password = $username; // Password sama dengan username
        $c->fullname = 'Voucher-' . $username;
        $c->email = $username . '@voucher.local';
        $c->phonenumber = '0000000000';
        $c->address = 'Auto Generated';
        $c->service_type = 'Voucher';
        $c->account_type = 'Personal';
        $c->created_date = date('Y-m-d H:i:s');
        
        if ($c->save()) {
            return [
                'id' => $c->id(),
                'username' => $username,
                'password' => $username
            ];
        }
        
        return false;
    }

    // Fungsi untuk aktivasi plan ke user
    private function activateUserPlan($customer_id, $plan, $router_name)
    {
        global $config;
        
        $customer = ORM::for_table('tbl_customers')->find_one($customer_id);
        if (!$customer) {
            return false;
        }
        
        // Hitung waktu expired
        $now = new DateTime();
        switch ($plan['validity_unit']) {
            case 'Hrs':
                $expired = $now->add(new DateInterval('PT' . $plan['validity'] . 'H'));
                break;
            case 'Days':
                $expired = $now->add(new DateInterval('P' . $plan['validity'] . 'D'));
                break;
            case 'Months':
                $expired = $now->add(new DateInterval('P' . $plan['validity'] . 'M'));
                break;
            default:
                $expired = $now->add(new DateInterval('P1D'));
        }
        
        // Simpan user recharge
        $r = ORM::for_table('tbl_user_recharges')->create();
        $r->customer_id = $customer_id;
        $r->username = $customer['username'];
        $r->plan_id = $plan['id'];
        $r->namebp = $plan['name_plan'];
        $r->recharged_on = date('Y-m-d H:i:s');
        $r->recharged_time = date('Y-m-d H:i:s');
        $r->expiration = $expired->format('Y-m-d H:i:s');
        $r->time = $expired->format('Y-m-d H:i:s');
        $r->status = 'on';
        $r->method = 'Voucher';
        $r->routers = $router_name;
        $r->type = $plan['type'];
        $r->save();
        
        // Update customer dengan plan aktif
        $customer->service_type = $plan['type'];
        $customer->username = $customer['username'];
        $customer->password = $customer['password'];
        $customer->pppoe_username = $customer['username'];
        $customer->pppoe_password = $customer['password'];
        $customer->save();
        
        // Tambahkan ke router menggunakan Package yang sesuai
        if ($router_name != 'radius') {
            try {
                $mikrotik = Mikrotik::info($router_name);
                if ($mikrotik) {
                    // Gunakan fungsi bawaan PHPNuxBill untuk add customer
                    if ($plan['type'] == 'Hotspot') {
                        Package::rechargeUser($customer_id, $router_name, $plan['id'], 'Voucher');
                    } else if ($plan['type'] == 'PPPOE') {
                        Package::rechargeUser($customer_id, $router_name, $plan['id'], 'Voucher');
                    }
                }
            } catch (Exception $e) {
                // Log error tapi tetap return true karena database sudah tersimpan
                error_log("Voucher Plugin - Router Error: " . $e->getMessage());
            }
        }
        
        return true;
    }

    // Remove Customer to Mikrotik/Device
    function remove_customer($customer, $plan)
    {
        // Expired voucher akan di-handle otomatis oleh cron job PHPNuxBill
    }

    // customer change username
    public function change_username($from, $to)
    {
        // Untuk voucher sekali pakai, username tidak perlu diubah
    }

    // Add Plan to Mikrotik/Device
    function add_plan($plan)
    {
        // Plan sudah ada di database
    }

    // Update Plan to Mikrotik/Device
    function update_plan($old_name, $plan)
    {
        // Plan sudah ada di database
    }

    // Remove Plan from Mikrotik/Device
    function remove_plan($plan)
    {
        // Plan sudah ada di database
    }

    // check if customer is online
    function online_customer($customer, $router_name)
    {
        return false;
    }

    // make customer online
    function connect_customer($customer, $ip, $mac_address, $router_name)
    {
        // Di-handle oleh hotspot
    }

    // make customer disconnect
    function disconnect_customer($customer, $router_name)
    {
        // Di-handle oleh hotspot
    }

    // Function untuk generate multiple vouchers sekaligus
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
            
            if (ORM::for_table('tbl_customers')->where('username', $code)->find_one()) {
                goto repeat;
            }

            // Create dummy customer
            $dummy_customer = $this->createDummyCustomer($code);
            
            if (!$dummy_customer) {
                $failed++;
                continue;
            }
            
            // Activate plan
            $activated = $this->activateUserPlan($dummy_customer['id'], $p, $router_name);
            
            if (!$activated) {
                ORM::for_table('tbl_customers')->where('id', $dummy_customer['id'])->delete_many();
                $failed++;
                continue;
            }
            
            // Save voucher record
            $d = ORM::for_table('tbl_voucher')->create();
            $d->type = $p['type'];
            $d->routers = $router_name;
            $d->id_plan = $p['id'];
            $d->code = $code;
            $d->user = $code;
            $d->status = '1';
            $d->generated_by = 'admin';
            $d->generated_date = date('Y-m-d H:i:s');
            
            if ($d->save()) {
                $vouchers[] = [
                    'code' => $code,
                    'username' => $code,
                    'password' => $code,
                    'plan' => $p['name_plan'],
                    'validity' => $p['validity'] . ' ' . $p['validity_unit']
                ];
            }
            
            // Delay untuk menghindari konflik
            usleep(200000); // 0.2 detik
        }

        return [
            'success' => true, 
            'vouchers' => $vouchers, 
            'count' => count($vouchers),
            'failed' => $failed,
            'total' => $quantity
        ];
    }
}
