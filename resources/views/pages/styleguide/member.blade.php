{{-- /styleguide/member — the member chrome (header, drawer, tab bar) with demo data. Local only. --}}
<x-layouts::member
    title="Member chrome | Styleguide"
    :member="new \App\Data\Profile\MemberChromeData(
        name: 'Sally Roberts',
        code: 'OPM10001',
        planLabel: 'Free',
        photoUrl: asset('images/home/profile2.webp'),
        unreadNotifications: 3,
    )">
    <section class="dashboard-section">
        <div class="container">
            <h1>Member chrome preview</h1>
            <p class="text-muted-brand">Links to member pages appear as their routes are built (P1+).</p>
        </div>
    </section>
</x-layouts::member>
