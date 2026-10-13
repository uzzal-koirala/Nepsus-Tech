<?php

namespace Tests\Feature;

use App\Models\CctvAccessLog;
use App\Models\CctvCamera;
use App\Models\Setting;
use App\Models\User;
use App\Support\CctvGateway;
use App\Support\CctvToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

class CctvTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function camera(array $attributes = []): CctvCamera
    {
        return CctvCamera::create($attributes + ['name' => 'Office Entrance', 'location' => 'Front door', 'stream_path' => 'office-entrance', 'camera_ip' => '192.168.1.64', 'enabled' => true]);
    }

    public function test_only_admins_and_allowed_staff_can_open_cctv(): void
    {
        $this->get(route('cctv.index'))->assertRedirect(route('login'));

        $staff = User::factory()->create();
        $this->actingAs($staff)->get(route('cctv.index'))->assertForbidden();
        $this->get(route('dashboard'))->assertOk()->assertDontSee('CCTV Monitoring');

        $staff->forceFill(['can_view_cctv' => true])->save();
        $this->get(route('cctv.index'))->assertOk()->assertSee('CCTV Monitoring');
        $this->get(route('cctv.cameras'))->assertForbidden();
        $this->get(route('cctv.logs'))->assertForbidden();
        $this->get(route('cctv.gateway-setup'))->assertForbidden();

        $this->actingAs($this->admin());
        $this->get(route('cctv.index'))->assertOk()->assertSee(['Live', 'Cameras', 'Access log']);
        $this->get(route('cctv.cameras'))->assertOk();
        $this->get(route('cctv.logs'))->assertOk();
    }

    public function test_livewire_actions_are_checked_on_the_server_too(): void
    {
        $camera = $this->camera();
        Setting::put(['cctv_gateway_url' => 'https://office-pc.tail1234.ts.net']);

        // A user without permission cannot load the component or ask for a stream token.
        $this->actingAs(User::factory()->create());
        Livewire::test('pages::cctv.index')->assertForbidden();
        Livewire::test('pages::cctv.cameras')->assertForbidden();
        $this->assertSame(0, CctvAccessLog::count());

        $this->actingAs(User::factory()->create(['can_view_cctv' => true]));
        Livewire::test('pages::cctv.index')->call('access', $camera->id)->assertOk();
        $this->assertSame(1, CctvAccessLog::count());
    }

    public function test_stream_gives_a_short_lived_token_for_one_camera_and_logs_the_view(): void
    {
        $viewer = User::factory()->create(['name' => 'Sita Rai', 'can_view_cctv' => true]);
        $camera = $this->camera();
        $disabled = $this->camera(['name' => 'Store', 'stream_path' => 'store', 'enabled' => false]);
        $this->actingAs($viewer);

        // No gateway yet: a helpful error, nothing logged.
        $page = Livewire::test('pages::cctv.index')->assertSee('CCTV is not set up yet');
        $this->assertSame(['error' => 'The CCTV gateway address is not set yet.'], $page->instance()->access($camera->id));

        Setting::put(['cctv_gateway_url' => 'https://office-pc.tail1234.ts.net/']);
        $page = Livewire::test('pages::cctv.index')->assertSee(['Office Entrance', 'Front door'])->assertDontSee('Store');
        $access = $page->instance()->access($camera->id);

        $this->assertSame('https://office-pc.tail1234.ts.net/office-entrance/index.m3u8', $access['url']);
        $this->assertStringNotContainsString('192.168.1.64', json_encode($access));

        // The token is signed with the key published at /api/cctv/jwks and allows reading this camera only.
        [$head, $body, $sig] = explode('.', $access['token']);
        $claims = json_decode(base64_decode(strtr($body, '-_', '+/')), true);
        $this->assertSame([['action' => 'read', 'path' => 'office-entrance']], $claims['mediamtx_permissions']);
        $this->assertSame((string) $viewer->id, $claims['sub']);
        $this->assertSame(CctvToken::TTL_MINUTES * 60, $claims['exp'] - $claims['iat']);

        $jwk = $this->getJson(route('api.cctv.jwks'))->assertOk()->json('keys.0');
        $this->assertSame(['RSA', 'RS256'], [$jwk['kty'], $jwk['alg']]);
        $this->assertSame(json_decode(base64_decode(strtr($head, '-_', '+/')), true)['kid'], $jwk['kid']);
        $this->assertSame(1, openssl_verify("$head.$body", base64_decode(strtr($sig, '-_', '+/')), $this->pemFromJwk($jwk), OPENSSL_ALGO_SHA256));

        $log = CctvAccessLog::sole();
        $this->assertSame([$viewer->id, $camera->id, 'Office Entrance', 'view'], [$log->user_id, $log->cctv_camera_id, $log->camera_name, $log->action]);

        // Disabled cameras cannot be streamed.
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $page->instance()->access($disabled->id);
    }

    public function test_admin_manages_cameras_and_who_can_watch(): void
    {
        $this->actingAs($this->admin());
        $staff = User::factory()->create(['name' => 'Hari Staff']);

        $page = Livewire::test('pages::cctv.cameras')
            ->call('create')
            ->set('form.name', 'Office Entrance')
            ->assertSet('form.stream_path', 'office-entrance')
            ->set('form.camera_ip', 'not-an-ip')
            ->set('form.rtsp_path', 'rtsp://admin:secret@192.168.1.64/x')
            ->call('save')
            ->assertHasErrors(['form.camera_ip', 'form.rtsp_path'])
            ->set('form.camera_ip', '192.168.1.64')
            ->set('form.rtsp_path', '/h264/ch1/main/av_stream')
            ->call('save')
            ->assertHasNoErrors()
            ->call('create')->set('form.name', 'Reception')->set('form.stream_path', 'office-entrance')
            ->call('save')->assertHasErrors(['form.stream_path'])
            ->set('form.stream_path', 'reception')->call('save')->assertHasNoErrors();

        [$entrance, $reception] = CctvCamera::orderBy('position')->get();
        $page->call('move', $reception->id, -1);
        $this->assertSame(['reception', 'office-entrance'], CctvCamera::orderBy('position')->pluck('stream_path')->all());

        $page->call('edit', $entrance->id)->set('form.location', 'Front door')->call('save')->assertHasNoErrors();
        $this->assertSame('Front door', $entrance->fresh()->location);

        $page->call('toggle', $entrance->id);
        $this->assertFalse($entrance->fresh()->enabled);

        $page->set('gateway', 'not a url')->call('saveGateway')->assertHasErrors(['gateway'])
            ->set('gateway', 'https://office-pc.tail1234.ts.net/')->call('saveGateway')->assertHasNoErrors();
        $this->assertSame('https://office-pc.tail1234.ts.net', CctvCamera::gateway());

        $page->call('toggleViewer', $staff->id);
        $this->assertTrue($staff->fresh()->can_view_cctv);

        CctvAccessLog::create(['user_id' => $staff->id, 'cctv_camera_id' => $reception->id, 'camera_name' => 'Reception']);
        $page->call('confirmDelete', $reception->id)->call('delete', $reception->id);
        $this->assertModelMissing($reception);
        $this->assertSame('Reception', CctvAccessLog::sole()->camera_name); // the log survives
    }

    public function test_gateway_setup_has_the_cameras_but_no_passwords(): void
    {
        $this->actingAs($this->admin());
        $this->camera();
        $this->camera(['name' => 'Reception', 'stream_path' => 'reception', 'camera_ip' => '192.168.1.65', 'rtsp_path' => '/h264/ch1/sub/av_stream']);

        $response = $this->get(route('cctv.gateway-setup'))->assertOk();
        $zip = new ZipArchive;
        $zip->open($response->baseResponse->getFile()->getPathname());
        $yml = $zip->getFromName('mediamtx.yml');

        $this->assertStringContainsString('authMethod: jwt', $yml);
        $this->assertStringContainsString('authJWTJWKS: '.url('/api/cctv/jwks'), $yml);
        $this->assertStringContainsString('hlsAddress: 127.0.0.1:8888', $yml);
        $this->assertStringContainsString('rtsp: false', $yml);
        $this->assertStringContainsString('moq: false', $yml);
        $this->assertStringContainsString('authJWTIssuer: '.rtrim(config('app.url'), '/'), $yml);
        $this->assertStringContainsString('source: rtsp://admin:'.CctvGateway::PASSWORD_PLACEHOLDER.'@192.168.1.64:554/h264/ch1/main/av_stream', $yml);
        $this->assertStringContainsString("  reception:\n", $yml);
        $this->assertStringContainsString("\r\n", $zip->getFromName('start-gateway.bat'));
        $this->assertNotFalse($zip->getFromName('README.txt'));
        $zip->close();
    }

    public function test_access_log_lists_who_watched_what(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        CctvAccessLog::create(['user_id' => $admin->id, 'camera_name' => 'Office Entrance', 'ip' => '10.0.0.7', 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0 Safari/537.36']);
        CctvAccessLog::create(['user_id' => $admin->id, 'camera_name' => 'Reception']);

        Livewire::test('pages::cctv.logs')
            ->assertSee(['Office Entrance', 'Reception', 'Chrome on Windows', '10.0.0.7', $admin->name])
            ->set('camera', 'Reception')
            ->assertDontSee('10.0.0.7');
    }

    /** PEM public key from a JWK, to check the signature independently. */
    private function pemFromJwk(array $jwk): string
    {
        $int = fn (string $b) => "\x02".$this->len($b = ord(($b = base64_decode(strtr($jwk[$b], '-_', '+/')))[0]) > 0x7F ? "\x00".$b : $b).$b;
        $seq = fn (string $b) => "\x30".$this->len($b).$b;
        $rsa = $seq($int('n').$int('e'));
        $bitString = "\x03".$this->len("\x00".$rsa)."\x00".$rsa;
        $der = $seq($seq("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00").$bitString);

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    private function len(string $b): string
    {
        $n = strlen($b);

        return $n < 128 ? chr($n) : ($n < 256 ? "\x81".chr($n) : "\x82".chr($n >> 8).chr($n & 0xFF));
    }
}
