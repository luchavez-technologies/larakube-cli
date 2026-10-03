{{-- ASP.NET Core production image: publish with the SDK, then run on the slim
     ASP.NET runtime. Runtime configuration (connection strings and so on)
     arrives through the environment, so nothing from .env is read here. --}}
############################################
# Build
############################################
FROM mcr.microsoft.com/dotnet/sdk:10.0-alpine AS builder
WORKDIR /src

# The project file first: packages only restore again when it changes.
COPY *.csproj ./
RUN dotnet restore

COPY . .
RUN dotnet publish -c Release -o /out --no-restore

############################################
# Production Image
############################################
FROM mcr.microsoft.com/dotnet/aspnet:10.0-alpine AS deploy
WORKDIR /app

ENV ASPNETCORE_URLS=http://+:{{ $config->framework->containerPort() }}
ENV DOTNET_EnableDiagnostics=0

COPY --from=builder /out ./

# The runtime image's built-in non-root user.
USER $APP_UID
EXPOSE {{ $config->framework->containerPort() }}

ENTRYPOINT ["dotnet", "{{ $config->getName() }}.dll"]
