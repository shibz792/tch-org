(() => {
  const state = { data: [], departments: [], filter: '', search: '', collapsed: new Set(), mobileCollapsed: new Set(), focusId: null, positions: new Map(), zoom: null };
  const chart = document.getElementById('chart');
  const mobile = document.getElementById('mobileChart');
  chart.querySelector('.loading')?.remove();
  const svg = d3.select(chart).append('svg').attr('width', '100%').attr('height', '100%');
  const defs = svg.append('defs');
  const outlineGradient = defs.append('linearGradient').attr('id', 'brandOutline').attr('x1', '0%').attr('y1', '0%').attr('x2', '100%').attr('y2', '100%');
  outlineGradient.append('stop').attr('offset', '0%').attr('stop-color', '#b8dc78');
  outlineGradient.append('stop').attr('offset', '48%').attr('stop-color', '#43b995');
  outlineGradient.append('stop').attr('offset', '100%').attr('stop-color', '#00a99d');
  const surfaceGradient = defs.append('linearGradient').attr('id', 'cardSurface').attr('x1', '0%').attr('y1', '0%').attr('x2', '100%').attr('y2', '100%');
  surfaceGradient.append('stop').attr('offset', '0%').attr('stop-color', '#28525a');
  surfaceGradient.append('stop').attr('offset', '55%').attr('stop-color', '#1d414b');
  surfaceGradient.append('stop').attr('offset', '100%').attr('stop-color', '#15343e');
  const hoverGradient = defs.append('linearGradient').attr('id', 'hoverSurface').attr('x1', '0%').attr('y1', '100%').attr('x2', '100%').attr('y2', '0%');
  hoverGradient.append('stop').attr('offset', '0%').attr('stop-color', '#33706e');
  hoverGradient.append('stop').attr('offset', '52%').attr('stop-color', '#24555a');
  hoverGradient.append('stop').attr('offset', '100%').attr('stop-color', '#193e49');
  const focusGradient = defs.append('linearGradient').attr('id', 'focusSurface').attr('x1', '0%').attr('y1', '0%').attr('x2', '100%').attr('y2', '100%');
  focusGradient.append('stop').attr('offset', '0%').attr('stop-color', '#34706c');
  focusGradient.append('stop').attr('offset', '52%').attr('stop-color', '#24505a');
  focusGradient.append('stop').attr('offset', '100%').attr('stop-color', '#173a45');
  const darkSurfaceGradient = defs.append('linearGradient').attr('id', 'darkCardSurface').attr('x1', '0%').attr('y1', '0%').attr('x2', '100%').attr('y2', '100%');
  darkSurfaceGradient.append('stop').attr('offset', '0%').attr('stop-color', '#244750');
  darkSurfaceGradient.append('stop').attr('offset', '52%').attr('stop-color', '#19343f');
  darkSurfaceGradient.append('stop').attr('offset', '100%').attr('stop-color', '#102630');
  const darkHoverGradient = defs.append('linearGradient').attr('id', 'darkHoverSurface').attr('x1', '0%').attr('y1', '100%').attr('x2', '100%').attr('y2', '0%');
  darkHoverGradient.append('stop').attr('offset', '0%').attr('stop-color', '#2e6868');
  darkHoverGradient.append('stop').attr('offset', '52%').attr('stop-color', '#21505a');
  darkHoverGradient.append('stop').attr('offset', '100%').attr('stop-color', '#173844');
  const darkFocusGradient = defs.append('linearGradient').attr('id', 'darkFocusSurface').attr('x1', '0%').attr('y1', '0%').attr('x2', '100%').attr('y2', '100%');
  darkFocusGradient.append('stop').attr('offset', '0%').attr('stop-color', '#35736d');
  darkFocusGradient.append('stop').attr('offset', '50%').attr('stop-color', '#24535b');
  darkFocusGradient.append('stop').attr('offset', '100%').attr('stop-color', '#183943');
  const stage = svg.append('g');
  const linkLayer = stage.append('g').attr('class', 'link-layer');
  const energyTrailLayer = stage.append('g').attr('class', 'energy-trail-layer');
  const energyLayer = stage.append('g').attr('class', 'energy-layer');
  const nodeLayer = stage.append('g').attr('class', 'node-layer');
  const zoom = d3.zoom().scaleExtent([0.25, 2]).on('zoom', event => stage.attr('transform', event.transform));
  state.zoom = zoom;
  svg.call(zoom);

  const text = value => String(value ?? '');
  const photo = value => value || '';
  const textLines = (value, maxChars, maxLines = 2) => {
    const words = text(value).trim().split(/\s+/).filter(Boolean);
    const lines = [];
    for (const word of words) {
      const current = lines[lines.length - 1];
      if (!current || `${current} ${word}`.length > maxChars) lines.push(word);
      else lines[lines.length - 1] = `${current} ${word}`;
    }
    if (lines.length > maxLines) {
      lines.length = maxLines;
      lines[maxLines - 1] = `${lines[maxLines - 1].slice(0, Math.max(1, maxChars - 1))}…`;
    }
    return lines.length ? lines : [''];
  };
  const multiline = (selection, value, x, y, maxChars, maxLines, className, lineHeight = 16) => {
    const label = selection.append('text').attr('class', className).attr('x', x).attr('y', y);
    textLines(value, maxChars, maxLines).forEach((line, index) => {
      label.append('tspan').attr('x', x).attr('dy', index === 0 ? 0 : lineHeight).text(line);
    });
    return label;
  };
  const nameClass = value => {
    const length = text(value).trim().length;
    if (length > 23) return 'node-name node-name-long';
    if (length > 17) return 'node-name node-name-medium';
    return 'node-name';
  };
  const matches = p => {
    const haystack = `${p.name} ${p.title} ${p.department} ${p.location} ${p.warehouse_code} ${p.warehouse_name}`.toLowerCase();
    return (!state.search || haystack.includes(state.search)) && (!state.filter || text(p.department_id) === state.filter);
  };
  const prune = nodes => nodes.map(node => ({ ...node, children: prune(node.children || []) }))
    .filter(node => matches(node) || node.children.length);
  const cloneTree = node => ({ ...node, children: (node.children || []).map(cloneTree) });
  const focusTree = (nodes, id) => {
    const visit = node => {
      if (node.id === id) return cloneTree(node);
      for (const child of node.children || []) {
        const branch = visit(child);
        if (branch) return { ...node, children: [branch] };
      }
      return null;
    };
    return nodes.map(visit).filter(Boolean);
  };
  const cardSize = d => d.data.id === state.focusId ? { width: 390, height: 300 } : { width: 330, height: 210 };
  const cardTransform = d => {
    const size = cardSize(d);
    return `translate(${d.x - size.width / 2},${d.y})`;
  };

  function drawCard(visual, d) {
    visual.selectAll('*').remove();
    const person = d.data;
    const focused = person.id === state.focusId;
    const size = cardSize(d);
    const portraitRadius = focused ? 62 : 48;
    const portraitX = focused ? size.width / 2 : 68;
    const portraitY = focused ? 62 : 54;
    const surfaceY = focused ? 48 : 42;
    const surfaceHeight = size.height - surfaceY;
    const departmentColor = person.department_color || '#43b995';
    const clipId = `portrait-${person.id}-${focused ? 'focus' : 'card'}`;
    const clip = defs.selectAll(`#${clipId}`).data([null]).join('clipPath').attr('id', clipId);
    clip.selectAll('circle').data([null]).join('circle').attr('cx', portraitX).attr('cy', portraitY).attr('r', portraitRadius - 5);

    visual.append('rect').attr('class', 'node-aura').attr('x', 5).attr('y', surfaceY + 5)
      .attr('width', size.width - 10).attr('height', surfaceHeight - 5).attr('rx', focused ? 30 : 25);
    visual.append('rect').attr('class', 'node-surface').attr('x', 0).attr('y', surfaceY)
      .attr('width', size.width).attr('height', surfaceHeight).attr('rx', focused ? 30 : 25);
    visual.append('rect').attr('class', 'node-glow').attr('x', 1).attr('y', surfaceY + 1)
      .attr('width', size.width - 2).attr('height', surfaceHeight - 2).attr('rx', focused ? 29 : 24);
    visual.append('rect').attr('class', 'node-accent').attr('x', focused ? 30 : 132).attr('y', focused ? 157 : 79)
      .attr('width', focused ? size.width - 60 : size.width - 158).attr('height', 3).attr('rx', 2).attr('fill', departmentColor);
    visual.append('circle').attr('class', 'portrait-halo').attr('cx', portraitX).attr('cy', portraitY).attr('r', portraitRadius + 5);
    visual.append('circle').attr('class', 'portrait-bed').attr('cx', portraitX).attr('cy', portraitY).attr('r', portraitRadius);
    if (photo(person.photo_path)) {
      visual.append('image').attr('class', 'node-avatar').attr('href', photo(person.photo_path))
        .attr('x', portraitX - portraitRadius).attr('y', portraitY - portraitRadius)
        .attr('width', portraitRadius * 2).attr('height', portraitRadius * 2)
        .attr('preserveAspectRatio', 'xMidYMid slice').attr('clip-path', `url(#${clipId})`);
    } else {
      visual.append('text').attr('class', 'node-initials').attr('x', portraitX).attr('y', portraitY + 8)
        .attr('text-anchor', 'middle').text(text(person.name).split(/\s+/).map(part => part[0]).slice(0, 2).join(''));
    }

    if (focused) {
      const nameLines = textLines(person.name, 30, 2);
      const titleY = 187 + ((nameLines.length - 1) * 22) + 25;
      multiline(visual, person.name, size.width / 2, 187, 30, 2, nameClass(person.name), 22).attr('text-anchor', 'middle').selectAll('tspan').attr('x', size.width / 2);
      multiline(visual, person.title || 'Role not listed', size.width / 2, titleY, 44, 2, 'node-role', 17).attr('text-anchor', 'middle').selectAll('tspan').attr('x', size.width / 2);
      visual.append('text').attr('class', 'node-dept').attr('x', size.width / 2).attr('y', 147).attr('text-anchor', 'middle').text(person.department || 'Organization');
      const manager = d.parent?.data?.id ? d.parent.data.name : 'Top level';
      visual.append('text').attr('class', 'node-responsibility').attr('x', 30).attr('y', size.height - 18).text(`Reports to ${manager}`);
      visual.append('text').attr('class', 'profile-hint').attr('x', size.width - 30).attr('y', size.height - 18).attr('text-anchor', 'end').text('Click again to view profile');
    } else {
      const nameLines = textLines(person.name, 21, 2);
      const titleY = 111 + ((nameLines.length - 1) * 21) + 26;
      multiline(visual, person.name, 132, 111, 21, 2, nameClass(person.name), 21);
      multiline(visual, person.title || 'Role not listed', 132, titleY, 29, 2, 'node-role', 17);
      visual.append('text').attr('class', 'node-dept').attr('x', 132).attr('y', 72).text(person.department || 'Organization');
      visual.append('text').attr('class', 'node-responsibility').attr('x', 132).attr('y', size.height - 17)
        .text(`${person.direct_reports || 0} direct report${Number(person.direct_reports) === 1 ? '' : 's'}`);
    }
    if (person.is_cherry_global) {
      const badge = visual.append('g').attr('class', 'cherry-badge').attr('transform', `translate(${size.width - 91},${surfaceY + 15})`);
      badge.append('rect').attr('width', 76).attr('height', 22).attr('rx', 11);
      badge.append('text').attr('x', 38).attr('y', 14).attr('text-anchor', 'middle').text('CHERRY GLOBAL');
    }
    if (person.children?.length) {
      const collapse = visual.append('g').attr('class', 'collapse-control')
        .attr('transform', `translate(${size.width - 30},${size.height - 25})`)
        .attr('role', 'button').attr('aria-label', state.collapsed.has(person.id) ? 'Expand team' : 'Collapse team')
        .on('click', event => {
          event.stopPropagation();
          state.collapsed.has(person.id) ? state.collapsed.delete(person.id) : state.collapsed.add(person.id);
          render();
          setTimeout(fitToChart, 580);
        });
      collapse.append('circle').attr('r', 13);
      collapse.append('text').attr('text-anchor', 'middle').attr('y', 4).text(state.collapsed.has(person.id) ? '+' : '−');
    }
  }

  function render() {
    const filteredAll = prune(state.data);
    const filtered = state.focusId ? focusTree(filteredAll, state.focusId) : filteredAll;
    if (!filtered.length) {
      nodeLayer.selectAll('*').remove(); linkLayer.selectAll('*').remove(); energyTrailLayer.selectAll('*').remove(); energyLayer.selectAll('*').remove();
      nodeLayer.append('text').attr('class', 'empty-chart').attr('x', 40).attr('y', 60).text('No people match this view.');
      return;
    }
    nodeLayer.selectAll('.empty-chart').remove();
    const virtualRoot = { name: 'Organization', children: filtered };
    const root = d3.hierarchy(virtualRoot, d => state.collapsed.has(d.id) ? null : d.children);
    d3.tree().nodeSize(state.focusId ? [470, 360] : [380, 285])(root);
    const nodes = root.descendants().slice(1);
    const links = root.links().filter(l => l.target.depth > 1);
    const linkPath = d => {
      const sourceY = d.source.y + cardSize(d.source).height;
      const targetY = d.target.y;
      const middleY = sourceY + (targetY - sourceY) / 2;
      return `M${d.source.x},${sourceY}V${middleY}H${d.target.x}V${targetY}`;
    };
    const transition = svg.transition().duration(550).ease(d3.easeCubicInOut);
    const link = linkLayer.selectAll('.link').data(links, d => d.target.data.id);
    link.exit().transition(transition).style('opacity', 0).remove();
    link.enter().append('path').attr('class', 'link').attr('pathLength', 1)
      .attr('d', d => {
        const old = state.positions.get(d.source.data.id) || d.source;
        return `M${old.x},${old.y}V${old.y}H${old.x}V${old.y}`;
      })
      .merge(link).transition(transition).attr('d', linkPath).style('opacity', 1);
    const energyTrail = energyTrailLayer.selectAll('.link-energy-trail').data(links, d => d.target.data.id);
    energyTrail.exit().transition(transition).style('opacity', 0).remove();
    energyTrail.enter().append('path').attr('class', 'link-energy-trail').attr('pathLength', 1)
      .attr('d', d => {
        const old = state.positions.get(d.source.data.id) || d.source;
        return `M${old.x},${old.y}V${old.y}H${old.x}V${old.y}`;
      })
      .merge(energyTrail).transition(transition).attr('d', linkPath);
    const energy = energyLayer.selectAll('.link-energy').data(links, d => d.target.data.id);
    energy.exit().transition(transition).style('opacity', 0).remove();
    energy.enter().append('path').attr('class', 'link-energy').attr('pathLength', 1)
      .attr('d', d => {
        const old = state.positions.get(d.source.data.id) || d.source;
        return `M${old.x},${old.y}V${old.y}H${old.x}V${old.y}`;
      })
      .merge(energy).transition(transition).attr('d', linkPath);
    const nodeJoin = nodeLayer.selectAll('.node-card').data(nodes, d => d.data.id);
    nodeJoin.exit().transition(transition).style('opacity', 0).remove();
    const nodeEnter = nodeJoin.enter().append('g').attr('class', 'node-card').style('opacity', 0)
      .attr('transform', d => {
        const old = state.positions.get(d.data.id);
        return old ? `translate(${old.x},${old.y})` : cardTransform(d);
      })
      .attr('tabindex', 0).attr('role', 'button');
    nodeEnter.append('g').attr('class', 'card-visual');
    const node = nodeEnter.merge(nodeJoin)
      .attr('class', d => `node-card ${d.depth === 1 ? 'top-level' : d.data.direct_reports ? 'manager' : 'individual'}${d.data.id === state.focusId ? ' focused' : ''}${d.data.is_cherry_global ? ' cherry-global' : ''}`)
      .attr('aria-label', d => `${d.data.name}, ${d.data.title}`)
      .on('click', (event, d) => {
        event.stopPropagation();
        if (state.focusId === d.data.id) openProfile(d.data.id);
        else { state.focusId = d.data.id; render(); setTimeout(fitToChart, 580); }
      })
      .on('mouseenter', (_, d) => focusPath(d))
      .on('mouseleave', clearPath)
      .on('focus', (_, d) => focusPath(d))
      .on('blur', clearPath)
      .on('keydown', (event, d) => {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        event.preventDefault();
        if (state.focusId === d.data.id) openProfile(d.data.id);
        else { state.focusId = d.data.id; render(); setTimeout(fitToChart, 580); }
      });
    node.select('.card-visual').each(function(d) { drawCard(d3.select(this), d); });
    node.transition(transition).attr('transform', cardTransform).style('opacity', 1);
    nodes.forEach(d => state.positions.set(d.data.id, { x: d.x - cardSize(d).width / 2, y: d.y }));
    function focusPath(d) {
      const pathIds = new Set(d.ancestors().slice(0, -1).map(item => item.data.id));
      stage.classed('has-focus', true);
      stage.selectAll('.node-card').classed('path-focus', item => pathIds.has(item.data.id)).classed('path-muted', item => !pathIds.has(item.data.id));
      stage.selectAll('.link,.link-energy-trail,.link-energy').classed('path-focus', link => pathIds.has(link.target.data.id)).classed('path-muted', link => !pathIds.has(link.target.data.id));
    }
    function clearPath() {
      stage.classed('has-focus', false);
      stage.selectAll('.node-card,.link,.link-energy-trail,.link-energy').classed('path-focus', false).classed('path-muted', false);
    }
  }

  function renderMobile(nodes = state.data) {
    mobile.replaceChildren();
    const make = items => {
      const fragment = document.createDocumentFragment();
      items.forEach(person => {
        if (!matches(person) && !(person.children || []).some(matches)) return;
        const wrap = document.createElement('div');
        const button = document.createElement('button'); button.className = `mobile-person${person.is_cherry_global ? ' cherry-global' : ''}`;
        const avatar = document.createElement(photo(person.photo_path) ? 'img' : 'span');
        if (photo(person.photo_path)) { avatar.src = photo(person.photo_path); avatar.alt = ''; } else { avatar.className = 'avatar-fallback'; avatar.textContent = text(person.name).charAt(0); }
        const info = document.createElement('span'); const strong = document.createElement('strong'); const small = document.createElement('small'); const department = document.createElement('em');
        strong.textContent = person.name; small.textContent = person.title || 'Role not listed'; department.className = 'mobile-department'; department.textContent = person.department || 'Organization'; info.append(strong, small, department);
        const action = document.createElement('span'); action.className = 'mobile-profile-action'; action.textContent = 'Profile';
        button.append(avatar, info, action); button.addEventListener('click', () => openProfile(person.id)); wrap.append(button);
        if (person.children?.length) {
          const toggle = document.createElement('button'); toggle.className = 'mobile-branch-toggle';
          const isCollapsed = state.mobileCollapsed.has(person.id);
          toggle.textContent = `${isCollapsed ? 'Show' : 'Hide'} ${person.children.length} direct report${person.children.length === 1 ? '' : 's'}`;
          toggle.setAttribute('aria-expanded', String(!isCollapsed));
          toggle.addEventListener('click', () => {
            isCollapsed ? state.mobileCollapsed.delete(person.id) : state.mobileCollapsed.add(person.id);
            renderMobile();
          });
          wrap.append(toggle);
          const children = document.createElement('div'); children.className = `mobile-children${isCollapsed ? '' : ' open'}`; children.append(make(person.children)); wrap.append(children);
        }
        fragment.append(wrap);
      }); return fragment;
    };
    mobile.append(make(nodes));
  }

  async function openProfile(id) {
    const response = await fetch(`/api/?route=person&id=${encodeURIComponent(id)}`);
    const result = await response.json(); if (!result.success) return;
    const p = result.data; const content = document.getElementById('profileContent'); content.replaceChildren();
    const img = document.createElement(p.photo_path ? 'img' : 'div'); img.className = 'profile-photo'; if (p.photo_path) { img.src = p.photo_path; img.alt = ''; } else img.textContent = text(p.name).charAt(0);
    const dept = document.createElement('span'); dept.className = 'eyebrow'; dept.textContent = p.department || 'Organization';
    const name = document.createElement('h2'); name.textContent = p.name; const role = document.createElement('p'); role.className = 'profile-role'; role.textContent = p.title || 'Role not listed';
    const affiliation = document.createElement('div'); affiliation.className = p.is_cherry_global ? 'profile-affiliation cherry-global' : 'profile-affiliation';
    affiliation.textContent = p.is_cherry_global ? 'Cherry Global Resource' : 'Tech Cargo Hub Resource';
    const grid = document.createElement('div'); grid.className = 'profile-grid';
    [['Manager',p.manager_name||'Top level'],['Warehouse',p.warehouse_code ? `${p.warehouse_code}${p.warehouse_name ? ` · ${p.warehouse_name}` : ''}` : 'Not assigned'],['Location',p.location||p.warehouse_location||'Not listed'],['Email',p.email||'Not listed'],['Phone',p.phone||'Not listed']].forEach(([label,value]) => { const box=document.createElement('div');box.className='profile-field';const s=document.createElement('small');s.textContent=label;const v=document.createElement('strong');v.textContent=value;box.append(s,v);grid.append(box); });
    const bio = document.createElement('p'); bio.className = 'profile-bio'; bio.textContent = p.bio || 'Profile details will be added soon.';
    const teamSection = document.createElement('section'); teamSection.className = 'profile-team';
    const teamHeading = document.createElement('h3'); teamHeading.textContent = `Handles ${p.direct_reports?.length || 0} direct reports`; teamSection.append(teamHeading);
    if (p.direct_reports?.length) {
      p.direct_reports.forEach(report => {
        const item = document.createElement('button'); item.className = `profile-team-member${report.is_cherry_global ? ' cherry-global' : ''}`;
        const avatar = document.createElement(report.photo_path ? 'img' : 'span');
        if (report.photo_path) { avatar.src = report.photo_path; avatar.alt = ''; } else { avatar.textContent = text(report.name).charAt(0); }
        const details = document.createElement('span'); const reportName = document.createElement('strong'); const reportRole = document.createElement('small');
        reportName.textContent = report.name; reportRole.textContent = report.title || report.department || 'Role not listed'; details.append(reportName, reportRole);
        const arrow = document.createElement('span'); arrow.textContent = '›'; item.append(avatar, details, arrow);
        item.addEventListener('click', () => openProfile(report.id)); teamSection.append(item);
      });
    } else {
      const empty = document.createElement('p'); empty.className = 'profile-team-empty'; empty.textContent = 'This person has no direct reports.'; teamSection.append(empty);
    }
    content.append(img, dept, name, role, affiliation, grid, bio, teamSection);
    document.getElementById('profileDrawer').classList.add('open'); document.getElementById('profileDrawer').setAttribute('aria-hidden','false'); document.getElementById('drawerBackdrop').classList.add('open');
  }
  const close = () => { document.getElementById('profileDrawer').classList.remove('open'); document.getElementById('profileDrawer').setAttribute('aria-hidden','true'); document.getElementById('drawerBackdrop').classList.remove('open'); };
  document.getElementById('closeDrawer').onclick = close; document.getElementById('drawerBackdrop').onclick = close;
  document.getElementById('searchInput').oninput = e => { state.search = e.target.value.trim().toLowerCase(); render(); renderMobile(); };
  document.getElementById('departmentFilter').onchange = e => { state.filter = e.target.value; render(); renderMobile(); };
  document.getElementById('zoomIn').onclick = () => svg.transition().call(zoom.scaleBy, 1.25); document.getElementById('zoomOut').onclick = () => svg.transition().call(zoom.scaleBy, .8);
  const fitToChart = () => {
    const bounds = stage.node()?.getBBox();
    if (!bounds || !bounds.width || !bounds.height) return;
    const padding = 70;
    const scale = Math.max(.25, Math.min(1, Math.min((chart.clientWidth - padding * 2) / bounds.width, (chart.clientHeight - padding * 2) / bounds.height)));
    const x = chart.clientWidth / 2 - scale * (bounds.x + bounds.width / 2);
    const y = padding - scale * bounds.y;
    svg.transition().duration(350).call(zoom.transform, d3.zoomIdentity.translate(x, y).scale(scale));
  };
  svg.on('click.reset-focus', event => {
    if (event.defaultPrevented || !state.focusId || event.target !== svg.node()) return;
    state.focusId = null;
    render();
    setTimeout(fitToChart, 580);
  });
  document.getElementById('fitChart').onclick = fitToChart;
  const workspace = document.querySelector('.workspace');
  const presentButton = document.getElementById('fullscreen');
  const exitButton = document.getElementById('exitFullscreen');
  const enterPresentation = async () => {
    if (!document.fullscreenElement) await workspace.requestFullscreen?.();
  };
  const exitPresentation = async () => {
    if (document.fullscreenElement) await document.exitFullscreen?.();
  };
  presentButton.onclick = enterPresentation;
  exitButton.onclick = exitPresentation;
  document.addEventListener('fullscreenchange', () => {
    const presenting = document.fullscreenElement === workspace;
    workspace.classList.toggle('is-presenting', presenting);
    presentButton.textContent = presenting ? 'Presenting' : 'Present';
    presentButton.disabled = presenting;
    setTimeout(fitToChart, 120);
  });
  document.getElementById('printChart').onclick = () => window.print();
  const themeButton = document.getElementById('themeToggle');
  const applyTheme = dark => {
    document.documentElement.classList.toggle('dark', dark);
    themeButton.textContent = dark ? '☀' : '◐';
    themeButton.setAttribute('aria-label', dark ? 'Switch to light mode' : 'Switch to dark mode');
    try { localStorage.setItem('orgchart-theme', dark ? 'dark' : 'light'); } catch (_) {}
  };
  let savedTheme = '';
  try { savedTheme = localStorage.getItem('orgchart-theme') || ''; } catch (_) {}
  applyTheme(savedTheme === 'dark');
  themeButton.onclick = () => applyTheme(!document.documentElement.classList.contains('dark'));
  fetch('/api/?route=hierarchy').then(r => {
    if (!r.ok) throw new Error(`Chart request failed with status ${r.status}`);
    return r.json();
  }).then(result => {
    if (!result.success || !result.data?.hierarchy) throw new Error('Chart response is invalid');
    state.data = result.data.hierarchy; state.departments = result.data.departments;
    document.getElementById('peopleCount').textContent = count(state.data);
    const select = document.getElementById('departmentFilter'); state.departments.forEach(d => { const o=document.createElement('option');o.value=d.id;o.textContent=d.name;select.append(o); });
    render(); renderMobile(); fitToChart();
  }).catch(error => {
    console.error(error);
    svg.remove();
    const message = document.createElement('div');
    message.className = 'loading';
    message.textContent = 'The organization chart could not be loaded. Refresh the page to try again.';
    chart.append(message);
  });
  const count = nodes => nodes.reduce((total, n) => total + 1 + count(n.children || []), 0);
})();
