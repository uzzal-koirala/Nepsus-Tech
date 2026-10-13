<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class CamerasTest extends TestCase
{
    use RefreshDatabase;

    private function fakeEzviz(): void
    {
        Http::fake([
            'open.ezvizlife.com/api/lapp/token/get' => fn (Request $r) => $r['appSecret'] === 'good-secret'
                ? Http::response(['code' => '200', 'data' => ['accessToken' => 'at.123', 'areaDomain' => 'https://isgpopen.ezvizlife.com', 'expireTime' => 0]])
                : Http::response(['code' => '10017', 'msg' => 'appKey不存在']),
            'isgpopen.ezvizlife.com/api/lapp/camera/list' => Http::response(['code' => '200', 'data' => [
                ['deviceSerial' => 'BD1234567', 'channelNo' => 1, 'channelName' => 'Office door', 'status' => 1, 'isEncrypt' => 0, 'picUrl' => 'https://img.ezviz/door.jpg'],
                ['deviceSerial' => 'BD7654321', 'channelNo' => 1, 'channelName' => 'Parking', 'status' => 2, 'isEncrypt' => 1, 'picUrl' => ''],
            ]]),
            'isgpopen.ezvizlife.com/api/lapp/v2/live/address/get' => Http::response(['code' => '200', 'data' => ['url' => 'https://hls.ezviz/live/BD1234567.m3u8']]),
            'isgpopen.ezvizlife.com/api/lapp/device/capture' => Http::response(['code' => '200', 'data' => ['picUrl' => 'https://img.ezviz/snap.jpg']]),
        ]);
    }

    public function test_cameras_page_asks_to_connect_first(): void
    {
        $this->get(route('cameras.index'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create());
        $this->get(route('cameras.index'))->assertOk()->assertSee('Connect your EZVIZ cameras');
    }

    public function test_settings_save_and_test_the_ezviz_account(): void
    {
        $this->actingAs(User::factory()->create());
        $this->fakeEzviz();

        Livewire::test('pages::settings.cameras')
            ->call('save')
            ->assertHasErrors(['appKey', 'secret'])
            ->set('appKey', 'key-1')->set('secret', 'bad-secret')
            ->call('testConnection')
            ->assertSet('test.ok', false)
            ->assertSee('The AppKey or Secret is wrong.')
            ->set('secret', 'good-secret')
            ->call('testConnection')
            ->assertSet('test.ok', true)
            ->assertSee('Connected. 2 cameras found · 1 online')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('good-secret', Crypt::decryptString(Setting::get('ezviz_secret')));
    }

    public function test_cameras_are_listed_and_can_be_watched_live(): void
    {
        $this->actingAs(User::factory()->create());
        $this->fakeEzviz();
        Setting::put(['ezviz_app_key' => 'key-1', 'ezviz_secret' => Crypt::encryptString('good-secret')]);

        $page = Livewire::test('pages::cameras')
            ->assertSee(['Office door', 'Parking', 'Encrypted', '2 cameras', '1 online'])
            ->call('watch', 'BD1234567', 1)
            ->assertSet('liveUrl', 'https://hls.ezviz/live/BD1234567.m3u8')
            ->assertSee('Connecting to the camera')
            ->call('setQuality', 1)
            ->call('snapshot')
            ->assertSet('snapshotUrl', 'https://img.ezviz/snap.jpg')
            ->assertSee('Snapshot taken')
            ->call('stopWatching')
            ->assertSet('liveUrl', null);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'live/address/get') && $r['quality'] == 1 && $r['protocol'] == 2 && $r['accessToken'] === 'at.123');
        $this->assertSame(1, Http::recorded(fn (Request $r) => str_contains($r->url(), 'token/get'))->count()); // token is reused

        $page->call('watch', 'NOPE', 1)->assertNotFound();
    }

    public function test_an_ezviz_error_is_explained(): void
    {
        $this->actingAs(User::factory()->create());
        Setting::put(['ezviz_app_key' => 'key-1', 'ezviz_secret' => Crypt::encryptString('bad-secret')]);
        $this->fakeEzviz();

        Livewire::test('pages::cameras')->assertSee(['Cameras could not be loaded', 'The AppKey or Secret is wrong.']);
    }
}
