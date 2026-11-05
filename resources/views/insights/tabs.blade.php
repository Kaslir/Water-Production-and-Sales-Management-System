<nav class="panel px-3 pt-3 pb-0 mb-3" aria-label="Insights sections">
    <div class="nav nav-tabs border-0" role="tablist">
        <a class="nav-link {{ request()->routeIs('analytics.*') ? 'active' : '' }}" href="{{ route('analytics.index') }}" role="tab" aria-selected="{{ request()->routeIs('analytics.*') ? 'true' : 'false' }}">Analytics</a>
        <a class="nav-link {{ request()->routeIs('reports.*') ? 'active' : '' }}" href="{{ route('reports.index') }}" role="tab" aria-selected="{{ request()->routeIs('reports.*') ? 'true' : 'false' }}">Reports</a>
    </div>
</nav>
